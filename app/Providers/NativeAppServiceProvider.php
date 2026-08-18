<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Native\InstallIdentity;
use App\Services\Native\NativeNetworkInfo;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Native\Laravel\Client\Client;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\App;
use Native\Laravel\Facades\ChildProcess;
use Native\Laravel\Facades\Menu;
use Native\Laravel\Facades\MenuBar;
use Native\Laravel\Facades\Settings;
use Native\Laravel\Facades\Window;
use Throwable;

/**
 * The packaged .exe is a *server*, not a point-of-sale terminal: nobody
 * takes an order inside the Electron window. One machine in the cafe runs
 * it, and every actual user — waiter tablets, the kitchen screen, the
 * cashier, a customer scanning a table QR — reaches the app through an
 * ordinary browser over the LAN. So this provider's whole job is to bring
 * up the background services that make that possible (LAN web server,
 * Reverb, queue worker, scheduler), plus a status window and a tray icon
 * for whoever set the machine up.
 */
class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     */
    public function boot(): void
    {
        // boot() is invoked from NativePHP's POST to /_native/api/booted, so
        // it runs as an ordinary PHP request under max_execution_time (30s
        // by default). This is a one-shot startup hook, not a user-facing
        // request, and blowing the limit kills it mid-boot with a fatal
        // error — leaving services silently unstarted and no tray icon.
        // First-run setup below (migrate + seed) alone can outlast 30s.
        set_time_limit(0);

        Log::info('NativeAppServiceProvider::boot() starting.');

        $this->ensureDeviceId();

        // Before any child process is spawned: they read .env at their own
        // startup, so what this writes is what they will use. The parent
        // keeps the shipped values in memory for the rest of this boot, which
        // is harmless — only the control-panel routes run here, and they
        // neither use sessions (see routes/web.php) nor broadcast.
        app(InstallIdentity::class)->ensure();

        $this->ensureDatabaseIsReady();
        $this->openControlWindow();

        // No database gate around the services below, on purpose. NativePHP
        // forces database.default to its own 'nativephp' SQLite connection
        // (NativeServiceProvider::rewriteDatabase) — a local file that is
        // open by the time this runs, with nothing to wait for and no
        // network dependency that could make waiting worthwhile.

        // --host=0.0.0.0 rather than 127.0.0.1: binding to loopback would
        // make Reverb reachable only from this machine, so every other
        // device could load pages over the LAN but would never receive a
        // live update (new order in the kitchen, waiter call, table
        // status). --hostname must match the host the browser's Echo
        // client actually dials — see resources/js/echo.js, which falls
        // back to window.location.hostname.
        $this->startService('reverb', function () {
            ChildProcess::artisan([
                'reverb:start',
                '--host=0.0.0.0',
                '--hostname='.env('REVERB_HOST', '127.0.0.1'),
                '--port='.env('REVERB_PORT', 8080),
                '--no-interaction',
            ], 'reverb', null, true);
        });

        // NativePHP's own internal PHP server (the one serving the Electron
        // window) is bound to 127.0.0.1, so nothing else in the building
        // can reach it. This is a second, separate Laravel server on the
        // same code and database, listening on the LAN — it is what every
        // tablet, kitchen screen and customer phone actually talks to.
        if ((bool) env('LAN_SERVER_ENABLED', true)) {
            $this->startService('lan_server', function () {
                ChildProcess::artisan([
                    'serve',
                    '--host='.env('LAN_SERVER_HOST', '0.0.0.0'),
                    '--port='.env('LAN_SERVER_PORT', 8000),
                    // Without --no-reload this server never stays up:
                    // `artisan serve` watches .env's mtime and restarts on
                    // every change, and .env is written on a normal boot
                    // here — NativePHP rewrites REVERB_* into it each
                    // start, and the settings panel writes it whenever it's
                    // saved. The result is an endless "Environment
                    // modified. Restarting server..." loop that never holds
                    // the port long enough to serve anyone. Reloading on
                    // file changes is a development convenience with no
                    // place in the packaged app anyway: settings changes
                    // here are applied by the explicit restart button.
                    '--no-reload',
                ], 'lan_server', null, true);
            });
        }

        // Without this worker every queued print job (PrintOrderTicket,
        // PrintCashierReceipt) piles up in the `jobs` table and no kitchen
        // ticket or receipt is ever physically printed.
        //
        // Spawned directly rather than through QueueWorker::up(), which
        // reads nativephp.queue_workers — a config kept empty on purpose so
        // NativePHP stops re-starting the worker on every web request (see
        // the note in config/nativephp.php). Same command it would build.
        $this->startService('queue_default', function () {
            $worker = config('nativephp.orange_queue_worker');

            ChildProcess::artisan([
                app()->isLocal() ? 'queue:listen' : 'queue:work',
                '--name='.$worker['alias'],
                '--queue='.implode(',', $worker['queues']),
                '--memory='.$worker['memory_limit'],
                '--timeout='.$worker['timeout'],
                '--sleep='.$worker['sleep'],
            ], 'queue_'.$worker['alias'], null, true, [
                'memory_limit' => $worker['memory_limit'].'M',
            ]);
        });

        // bootstrap/app.php schedules app:check-printer-statuses every
        // minute — the sweep behind the green/red printer lights in the
        // admin panel. A schedule only runs if something calls
        // schedule:run, and in a desktop install there is no cron to do it.
        $this->startService('scheduler', function () {
            ChildProcess::artisan(['schedule:work', '--no-interaction'], 'scheduler', null, true);
        });

        if ((bool) env('NATIVE_START_AT_LOGIN', true)) {
            App::openAtLogin(true);
        }

        $port = (int) env('LAN_SERVER_PORT', 8000);
        $lanUrls = array_map(
            fn (string $ip) => "http://{$ip}:{$port}",
            app(NativeNetworkInfo::class)->lanIpAddresses()
        );

        $this->showTray("Orange Cafe — يعمل على المنفذ {$port}", $lanUrls);

        $this->recordRunningConfig();

        Log::info('NativeAppServiceProvider::boot() finished.');
    }

    /**
     * A packaged install starts on an empty SQLite file in the user's app
     * data directory — NativePHP creates the file but nothing has ever run a
     * migration against it. Without this, the very first launch on a
     * customer's machine serves "no such table: sessions" on every page.
     *
     * Migrations run on every boot (an upgrade arrives with a populated
     * database and new migrations to apply), and the seeding half is gated on
     * there being no administrator yet.
     *
     * Gating on "does the `users` table exist" instead looks equivalent and
     * is not: in development mode NativePHP creates its own
     * database/nativephp.sqlite and runs `native:migrate` against it before
     * this provider boots (NativeServiceProvider::rewriteDatabase). The table
     * therefore always exists by now under `native:serve`, and the seeding
     * would be skipped forever — leaving no languages, no roles and no
     * account to log in with.
     */
    private function ensureDatabaseIsReady(): void
    {
        try {
            Artisan::call('migrate', ['--force' => true]);

            if ($this->hasAdministrator()) {
                return;
            }

            Log::info('No administrator account found — running first-run setup.');

            Artisan::call('app:first-run-setup', [
                '--restaurant-name' => env('APP_NAME', 'My Restaurant'),
            ]);

            Log::info('First-run setup output: '.Artisan::output());
        } catch (Throwable $e) {
            // Never abort boot over this: the control window and the tray
            // still need to come up so whoever is installing can see what
            // went wrong instead of staring at a process that vanished.
            Log::error('Database preparation failed.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Deliberately not just "are there any users": a staff-only database
     * (say, every admin deleted by accident) still needs the setup to put an
     * administrator back, and app:first-run-setup is safe to re-run.
     */
    private function hasAdministrator(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasTable('roles')
            && User::role('admin')->exists();
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
     * always reflects the file as it is *now*, not as it was at boot. This is
     * the only place that knows the difference.
     */
    private function recordRunningConfig(): void
    {
        try {
            Settings::set('running_config', json_encode([
                'REVERB_HOST' => (string) env('REVERB_HOST', '127.0.0.1'),
                'REVERB_PORT' => (string) env('REVERB_PORT', 8080),
                'LAN_SERVER_PORT' => (string) env('LAN_SERVER_PORT', 8000),
            ]));
        } catch (Throwable $e) {
            Log::warning('Could not record the running configuration.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Starts a background service by alias, skipping it if already running
     * and never letting a failure here stop the other services queued up
     * after it in boot().
     */
    private function startService(string $alias, Closure $start): void
    {
        if (in_array($alias, $this->runningAliases(), true)) {
            Log::info("Native service [{$alias}] is already running — skipped.");

            return;
        }

        try {
            $start();
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

                return;
            }

            Log::error("Failed to start native service [{$alias}].", ['error' => $e->getMessage()]);
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
    private function runningAliases(): array
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
     * The window whoever sets up this machine actually looks at — live
     * status per service, the LAN addresses to type into the tablets, and
     * the handful of settings that differ per install. Opened before anyone
     * has logged in, so it doubles as the diagnostic tool when the app
     * itself isn't reachable.
     *
     * The URL is built from the CURRENT request's own host, not route() /
     * url() and not a relative path — both leave the window permanently
     * hidden:
     *   - route()/url() build on APP_URL, which has nothing to do with
     *     where NativePHP's internal PHP server actually is.
     *   - A relative path isn't valid for Electron's loadURL(), which needs
     *     an absolute URL — Electron treats it as a local file path, the
     *     load fails, and since NativePHP only calls window.show() on a
     *     successful load, the window exists but never becomes visible.
     * This boot() runs while handling the POST Electron makes to announce
     * the app has booted, so the current request's host — whatever
     * 127.0.0.1:<dynamic port> it came in on — is exactly the right
     * address, in dev and packaged mode alike.
     */
    private function openControlWindow(): void
    {
        try {
            $base = request()?->getSchemeAndHttpHost();

            if (! $base) {
                Log::warning('openControlWindow: no active request to derive the internal server URL from — skipping.');

                return;
            }

            Window::open('control-panel')
                ->url($base.'/native/panel')
                ->title('Orange Cafe — حالة الخادم')
                ->width(1040)
                ->height(660)
                ->resizable(true)
                ->maximizable(true);
        } catch (Throwable $e) {
            // Never let a window failure take the whole app down with it —
            // the LAN server and Reverb being up matters far more than this
            // window existing, and the tray menu is a fallback way in.
            Log::error('Failed to open the native control window.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  string[]  $lanUrls
     */
    private function showTray(string $status, array $lanUrls): void
    {
        $port = (int) env('LAN_SERVER_PORT', 8000);

        $items = [
            Menu::label($status),
            Menu::separator(),
            Menu::link("http://127.0.0.1:{$port}", 'افتح في المتصفح (هذا الجهاز)')->openInBrowser(true),
        ];

        foreach ($lanUrls as $url) {
            $items[] = Menu::link($url, "أجهزة أخرى: {$url}")->openInBrowser(true);
        }

        $items[] = Menu::separator();
        $items[] = Menu::quit('إغلاق الخادم');

        MenuBar::create()
            ->icon(public_path('icon.png'))
            ->tooltip('Orange Cafe Server')
            ->onlyShowContextMenu()
            // NativePHP's tray "/create" handler calls app.dock.hide()
            // whenever showDockIcon is falsy — app.dock only exists on
            // macOS, so on Windows/Linux that line throws ("Cannot read
            // properties of undefined (reading 'hide')") unless this is
            // explicitly true.
            ->showDockIcon(true)
            ->withContextMenu(Menu::make(...$items));
    }

    /**
     * A stable per-install identifier for support and diagnostics — not a
     * server-side registry, just enough to tell installs apart in logs.
     */
    private function ensureDeviceId(): void
    {
        try {
            if (! Settings::get('device_id')) {
                Settings::set('device_id', (string) Str::uuid());
            }
        } catch (Throwable $e) {
            Log::warning('Could not read/write the native device id.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * php.ini directives for the bundled runtime.
     */
    public function phpIni(): array
    {
        return [
            // Rasterized thermal tickets and uploaded menu photos are both
            // held in memory as GD images; 128M (the NativePHP default) is
            // enough for a receipt but not for a 4000px phone photo being
            // resized on upload.
            'memory_limit' => '512M',
            'upload_max_filesize' => '20M',
            'post_max_size' => '24M',
            // mPDF's Arabic shaping and the QR/barcode renderers are both
            // slower than a typical web request; the receipt PDF for a long
            // order can genuinely take a few seconds.
            'max_execution_time' => '120',
        ];
    }
}
