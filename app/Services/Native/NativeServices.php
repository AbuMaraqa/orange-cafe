<?php

namespace App\Services\Native;

use Illuminate\Support\Facades\Log;
use App\Providers\NativeAppServiceProvider;
use Native\Laravel\Client\Client;
use Native\Laravel\Facades\ChildProcess;
use Native\Laravel\Facades\Settings;
use Throwable;

/**
 * The four background processes that make the packaged app a server —
 * Reverb, the LAN web server, the queue worker and the scheduler.
 *
 * Spawning lives here rather than in NativeAppServiceProvider because boot
 * is not the only place that needs it: the control panel's "restart" button
 * has to bring the same four processes up again with the settings that were
 * just saved. The daemon's own child-process/restart endpoint cannot do
 * that — it re-spawns from the argv it stored when the process was first
 * started, so a changed Reverb or LAN port would be silently ignored and the
 * panel would report a successful restart onto the old ports.
 */
class NativeServices
{
    /**
     * php.ini overrides for the processes that are meant to never return.
     *
     * NativeAppServiceProvider::phpIni() sets max_execution_time=120 for the
     * bundled runtime, and NativePHP passes it as -d to *every* child PHP
     * process it spawns. On a daemon that is not a safety net, it is a
     * scheduled execution: Reverb, the queue worker and the scheduler each
     * died with "Maximum execution time of 120 seconds exceeded" exactly two
     * minutes after every start, taking live order updates and all printing
     * down with them while the pages still loaded and nothing in the UI said
     * why. `artisan serve` died the same way and left its `php -S` grandchild
     * holding port 8000 with no parent to restart it.
     *
     * 0 is what the CLI SAPI already uses when nothing overrides it. The
     * limit stays in place for the window server, where the panel restart,
     * legacy-import and backup routes are the ones that need it.
     */
    private const DAEMON_INI = ['max_execution_time' => '0'];

    /**
     * Aliases confirmed up during this call — populated by start().
     *
     * @var string[]
     */
    private array $started = [];

    public function __construct(
        private NativeEnvSettings $envSettings,
        private OrphanProcessCleaner $orphans,
    ) {}

    /**
     * A setting as it stands in the .env file right now.
     *
     * env() is wrong for this and only looks right at boot. Dotenv does not
     * overwrite variables that already exist in the real process environment,
     * and NativePHP hands every process it spawns — including the internal
     * server that serves the control panel — the environment it captured when
     * the app launched. So inside a panel request env() still reports the
     * values from launch, however recently the file was rewritten.
     *
     * That is precisely the case that matters here: the whole point of the
     * restart button is to apply settings that were saved a moment ago. Read
     * through it and a restart re-spawns every service on its old ports,
     * reports success, and leaves the panel flagging the change as still
     * pending — which is what it did.
     */
    private function setting(string $key, string $default): string
    {
        $value = $this->envSettings->get([$key])[$key];

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Per-install settings handed to each child as environment overrides.
     *
     * The daemon merges these over its own environment ({...process.env,
     * ...env} in childProcess.ts), and a child PHP process gives a real
     * environment variable precedence over the .env file — so without this a
     * saved change reaches only the process whose argv carries it. Reverb
     * would move to the new port while the LAN server and the queue worker,
     * still holding the launch-time value, kept publishing events to the old
     * one: live updates silently dead everywhere until the app was closed and
     * opened again.
     *
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        return [
            'REVERB_HOST' => $this->setting('REVERB_HOST', '127.0.0.1'),
            'REVERB_PORT' => $this->setting('REVERB_PORT', '8080'),
            // Both are rewritten per install by InstallIdentity at boot, and
            // a child that resolved them from a cached config instead would
            // sign its broadcasts with the shipped placeholder — accepted by
            // nothing. Passing them explicitly means the queue worker, the
            // LAN server and Reverb itself always agree on the same secret.
            'APP_KEY' => $this->setting('APP_KEY', ''),
            'REVERB_APP_SECRET' => $this->setting('REVERB_APP_SECRET', ''),
            'LAN_SERVER_PORT' => $this->setting('LAN_SERVER_PORT', '8000'),
            'PUBLIC_BASE_URL' => $this->setting('PUBLIC_BASE_URL', ''),
        ];
    }

    /**
     * A directory holding a php.ini for the LAN server, or null if one
     * could not be written.
     *
     * NativeAppServiceProvider::phpIni() is handed to NativePHP, which
     * passes it as -d to the processes it spawns. That covers this app's
     * children — but not the server that actually answers the tablets.
     * `artisan serve` starts `php -S` as a separate process and forwards no
     * -d at all (ServeCommand::serverCommand), so the LAN server has always
     * run on the bare cli-server defaults while every setting meant for it
     * was applied to the supervisor that spawned it.
     *
     * What that cost, concretely:
     *
     *  - `upload_max_filesize` 2M instead of 20M, so a photo from a phone —
     *    3-8M is ordinary — was rejected by PHP before Laravel ever saw the
     *    request, and the admin got "the image failed to upload" for a
     *    perfectly good JPEG.
     *  - `memory_limit` 128M instead of 512M, which is not enough to resize
     *    that photo even once it arrives.
     *  - none of the opcache settings, which are the ones that stop a
     *    cli-server dying on an ASLR collision at startup (see phpIni()).
     *    The LAN server is a cli-server too, and was exposed to exactly the
     *    crash they exist to prevent.
     *
     * ini_set() cannot fix the first two: upload_max_filesize and
     * post_max_size are PHP_INI_PERDIR and are read once, before any
     * application code runs. A real ini file is the only lever, and PHPRC
     * is how PHP is told where to find one. `artisan serve` hands its
     * environment to the `php -S` it spawns, so setting it on the parent
     * reaches the child that needs it.
     */
    private function lanServerIniDirectory(): ?string
    {
        try {
            $directory = storage_path('framework/php-ini');

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                return null;
            }

            $lines = [];

            foreach (app(NativeAppServiceProvider::class)->phpIni() as $key => $value) {
                $lines[] = $key.'='.$value;
            }

            if (@file_put_contents($directory.DIRECTORY_SEPARATOR.'php.ini', implode("\n", $lines)."\n") === false) {
                return null;
            }

            return $directory;
        } catch (Throwable $e) {
            // Worth a line in the log, never worth refusing to start the
            // server over: without it uploads stay capped at 2M, which is
            // the behaviour that shipped for months.
            Log::warning('Could not write the LAN server php.ini.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Starts every service that is not already registered with the daemon.
     *
     * @return string[] the aliases now running
     */
    public function startAll(): array
    {
        $this->started = [];
        $env = $this->childEnv();

        // Anything still holding our ports is a leftover from a previous run
        // that did not shut down cleanly. Left in place, the new servers
        // cannot bind: the app opens, reports every service started, and
        // serves nothing — or worse, serves the old version still answering
        // on that port.
        $this->orphans->clear([
            (int) $this->setting('LAN_SERVER_PORT', '8000'),
            (int) $this->setting('REVERB_PORT', '8080'),
        ]);

        // --host=0.0.0.0 rather than 127.0.0.1: binding to loopback would
        // make Reverb reachable only from this machine, so every other
        // device could load pages over the LAN but would never receive a
        // live update (new order in the kitchen, waiter call, table status).
        //
        // No --hostname is passed. Reverb uses it for exactly one thing —
        // looking up a TLS certificate for that name (Servers\Reverb\Factory
        // ::configureTls) — and this server speaks plain ws:// on the LAN.
        // It never restricted which Host header was accepted, so the value
        // it used to carry (REVERB_HOST) only created the impression that
        // browsers had to dial that same address. They dial whatever host
        // they loaded the page from; see resources/js/echo.js.
        $this->start('reverb', fn () => ChildProcess::artisan([
            'reverb:start',
            '--host=0.0.0.0',
            '--port='.$this->setting('REVERB_PORT', '8080'),
            '--no-interaction',
        ], 'reverb', $env, true, self::DAEMON_INI));

        // NativePHP's own internal PHP server (the one serving the Electron
        // window) is bound to 127.0.0.1, so nothing else in the building can
        // reach it. This is a second, separate Laravel server on the same
        // code and database, listening on the LAN — it is what every tablet,
        // kitchen screen and customer phone actually talks to.
        if (filter_var($this->setting('LAN_SERVER_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            // PHPRC is the only channel that reaches the `php -S` process
            // artisan serve spawns — see lanServerIniDirectory() for what
            // was silently missing from it, and why -d cannot get there.
            $lanIniDirectory = $this->lanServerIniDirectory();
            $lanEnv = $lanIniDirectory ? [...$env, 'PHPRC' => $lanIniDirectory] : $env;

            $this->start('lan_server', fn () => ChildProcess::artisan([
                'serve',
                '--host='.$this->setting('LAN_SERVER_HOST', '0.0.0.0'),
                '--port='.$this->setting('LAN_SERVER_PORT', '8000'),
                // Without --no-reload this server never stays up: `artisan
                // serve` watches .env's mtime and restarts on every change,
                // and .env is written on a normal boot here — NativePHP
                // rewrites REVERB_* into it each start, and the settings
                // panel writes it whenever it's saved. The result is an
                // endless "Environment modified. Restarting server..." loop
                // that never holds the port long enough to serve anyone.
                // Reloading on file changes is a development convenience
                // with no place in the packaged app anyway: settings changes
                // here are applied by the explicit restart button.
                '--no-reload',
            ], 'lan_server', $lanEnv, true, self::DAEMON_INI));
        }

        // Without this worker every queued print job (PrintOrderTicket,
        // PrintCashierReceipt) piles up in the `jobs` table and no kitchen
        // ticket or receipt is ever physically printed.
        //
        // Spawned directly rather than through QueueWorker::up(), which
        // reads nativephp.queue_workers — a config kept empty on purpose so
        // NativePHP stops re-starting the worker on every web request (see
        // the note in config/nativephp.php). Same command it would build.
        $worker = config('nativephp.orange_queue_worker');

        $this->start('queue_'.$worker['alias'], fn () => ChildProcess::artisan([
            app()->isLocal() ? 'queue:listen' : 'queue:work',
            '--name='.$worker['alias'],
            '--queue='.implode(',', $worker['queues']),
            '--memory='.$worker['memory_limit'],
            '--timeout='.$worker['timeout'],
            '--sleep='.$worker['sleep'],
        ], 'queue_'.$worker['alias'], $env, true, [
            ...self::DAEMON_INI,
            'memory_limit' => $worker['memory_limit'].'M',
        ]));

        // bootstrap/app.php schedules app:check-printer-statuses every
        // minute — the sweep behind the green/red printer lights in the
        // admin panel. A schedule only runs if something calls schedule:run,
        // and in a desktop install there is no cron to do it.
        $this->start('scheduler', fn () => ChildProcess::artisan(
            ['schedule:work', '--no-interaction'], 'scheduler', $env, true, self::DAEMON_INI
        ));

        return $this->started;
    }

    /**
     * Stops every registered service and waits for the ports to come free.
     *
     * Separate from restartAll() because a restore has to happen in the gap
     * between the two halves: the database file cannot be replaced while
     * anything still has it open.
     */
    public function stopAll(): void
    {
        foreach ($this->runningAliases() as $alias) {
            $this->stop($alias);
        }

        $this->waitUntilStopped();
    }

    /**
     * Stops all four services and starts them again from the current .env.
     *
     * Used by the control panel instead of App::relaunch(), which cannot
     * work from a web request: NativePHP's /app/relaunch handler calls
     * app.relaunch() and app.quit() and then never sends a response, so the
     * caller's HTTP request dies with the process it just killed and the
     * panel reports a failure whatever actually happened to the app.
     *
     * Restarting only the children is also the smaller hammer — the Electron
     * window, the tray icon and this very request survive it, and the
     * children are the only processes that read .env in the first place.
     *
     * @return string[] the aliases that came back up
     */
    public function restartAll(): array
    {
        foreach ($this->runningAliases() as $alias) {
            $this->stop($alias);
        }

        // The daemon deletes its process entry asynchronously, after the OS
        // reports the process gone. Starting an alias that is still
        // registered is a no-op on the Electron side, which would leave the
        // old process running on the old port and make the restart look like
        // it silently did nothing — precisely the bug being fixed.
        $this->waitUntilStopped();

        $restarted = $this->startAll();
        $this->recordRunningConfig();

        return $restarted;
    }

    /**
     * Remember the settings the child processes were actually started with,
     * so the control panel can tell "Reverb is down" apart from "you changed
     * the port and haven't restarted yet".
     *
     * Those two look identical from a port probe, and conflating them is
     * alarming in the wrong direction: saving a new Reverb port turns the
     * status light red even though live updates are working perfectly on the
     * old one, because the panel probes the address it just saved while the
     * server is still listening on the previous one.
     *
     * Comparing .env against env() in the panel would not work: the internal
     * PHP server re-bootstraps Laravel on every request, so env() there
     * always reflects the file as it is *now*, not as it was at boot.
     */
    public function recordRunningConfig(): void
    {
        try {
            Settings::set('running_config', json_encode([
                'REVERB_PORT' => $this->setting('REVERB_PORT', '8080'),
                'LAN_SERVER_PORT' => $this->setting('LAN_SERVER_PORT', '8000'),
            ]));
        } catch (Throwable $e) {
            Log::warning('Could not record the running configuration.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Aliases the NativePHP daemon currently has registered, read straight
     * off its HTTP API instead of through ChildProcess::get()/all().
     *
     * Those hydrate every result through ChildProcess::fromRuntimeProcess(),
     * which does `foreach ($process['settings'] as ...)` — and the daemon
     * returns its stored process object *without* a `settings` key for an
     * already-running alias. The whole check then dies on "Trying to access
     * array offset on null", which the caller could only read as "this
     * service failed", so every service came back reported as failed on a
     * second boot while all four were in fact running.
     *
     * The raw response needs no hydration, so it cannot hit that.
     *
     * @return string[]
     */
    public function runningAliases(): array
    {
        try {
            $processes = app(Client::class)->get('child-process/')->json();

            return is_array($processes) ? array_keys($processes) : [];
        } catch (Throwable $e) {
            // Outside the native runtime there is no daemon to ask. Reporting
            // "nothing is running" is the safe answer: starting an alias that
            // already exists is a no-op on the Electron side, whereas
            // skipping one that isn't running leaves the app without it.
            Log::warning('Could not list running native services.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Starts one service by alias, skipping it if already running and never
     * letting a failure stop the services queued up after it.
     */
    private function start(string $alias, callable $spawn): void
    {
        if (in_array($alias, $this->runningAliases(), true)) {
            Log::info("Native service [{$alias}] is already running — skipped.");
            $this->started[] = $alias;

            return;
        }

        try {
            $spawn();
            $this->started[] = $alias;
            Log::info("Native service [{$alias}] started.");
        } catch (Throwable $e) {
            // A hydration crash (see runningAliases()) can surface here too,
            // on a relaunch where the alias is already registered: NativePHP's
            // Electron side returns the stored process object as-is for a
            // duplicate alias, and ChildProcess::start() then chokes hydrating
            // it. Re-check before crying failure, or the log fills with
            // "Failed to start" for services that are running perfectly.
            if (in_array($alias, $this->runningAliases(), true)) {
                Log::info("Native service [{$alias}] was already registered — treating as running.");
                $this->started[] = $alias;

                return;
            }

            Log::error("Failed to start native service [{$alias}].", ['error' => $e->getMessage()]);
        }
    }

    private function stop(string $alias): void
    {
        try {
            ChildProcess::stop($alias);
        } catch (Throwable $e) {
            Log::warning("Could not stop native service [{$alias}].", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Polls until the daemon has no processes left, or the budget runs out.
     * `artisan serve` and `reverb:start` both hold a listening socket, and
     * the port stays bound for a moment after the process is signalled —
     * starting the replacement too early makes it fail to bind instead.
     */
    private function waitUntilStopped(int $timeoutMs = 6000, int $intervalMs = 200): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            if ($this->runningAliases() === []) {
                // The sockets outlive the process by a beat.
                usleep(500_000);

                return;
            }

            usleep($intervalMs * 1000);
        }

        Log::warning('Native services did not all stop within the restart budget — starting anyway.');
    }
}
