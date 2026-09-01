<?php

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Services\Native\BackupService;
use App\Services\Native\LanReachability;
use App\Services\Native\NativeEnvSettings;
use App\Services\Native\NativeNetworkInfo;
use App\Services\Native\NativeServices;
use App\Services\Native\PublicUrlResolver;
use App\Services\Printing\PopplerBinary;
use App\Services\Printing\UsbPrinterTransport;
use Database\Seeders\LegacyOrangeDataSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Native\Laravel\Client\Client;
use Native\Laravel\Facades\App as NativeApp;
use Native\Laravel\Facades\Clipboard;
use Native\Laravel\Facades\Settings as NativeSettings;

/**
 * Backs the native control window (/native/panel) — the only interface the
 * packaged .exe has. Whoever installs the app on the cafe's server machine
 * uses it to see that every background service came up, to copy the LAN
 * address into the tablets, and to fix the few settings that differ per
 * install, all before any staff account exists to log in with.
 *
 * Deliberately has no cloud/sync action: this app's data lives entirely on
 * this machine.
 */
class NativeSettingsController extends Controller
{
    /**
     * The keys the panel can read and write. Kept in one place because the
     * form, the validator and the .env writer all have to agree on them.
     */
    private const EDITABLE_KEYS = [
        'REVERB_PORT', 'LAN_SERVER_PORT', 'PUBLIC_BASE_URL',
    ];

    public function panel(
        NativeNetworkInfo $network,
        NativeEnvSettings $envSettings,
        PublicUrlResolver $publicUrl,
    ) {
        $env = $envSettings->get(self::EDITABLE_KEYS);
        $port = (int) ($env['LAN_SERVER_PORT'] ?: 8000);

        return view('native.panel', [
            'network' => [
                'port' => $port,
                'lanUrls' => array_map(
                    fn (string $ip) => "http://{$ip}:{$port}",
                    $network->lanIpAddresses()
                ),
                'editable' => $env,
                // Shown as the placeholder when PUBLIC_BASE_URL is blank, so
                // it's obvious what the QR codes will actually encode.
                'detectedPublicUrl' => $publicUrl->base(),
            ],
            'firstRun' => $this->firstRunCredentials(),
            'backups' => app(BackupService::class)->available(),
        ]);
    }

    /**
     * The generated admin login from app:first-run-setup, shown on the panel
     * until whoever is installing dismisses it.
     *
     * Without this the first launch on a customer's machine creates an admin
     * account with a random password written to a file inside a per-user
     * application-data directory that nobody would think to look in — an app
     * that cannot be logged into.
     *
     * @return array{email: string, password: string}|null
     */
    private function firstRunCredentials(): ?array
    {
        $path = storage_path('first-run-credentials.txt');

        if (! is_file($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);

        preg_match('/^Email:\s*(.+)$/m', $contents, $email);
        preg_match('/^Password:\s*(.+)$/m', $contents, $password);

        if (! isset($email[1], $password[1])) {
            return null;
        }

        return ['email' => trim($email[1]), 'password' => trim($password[1])];
    }

    /**
     * Deletes the generated-credentials file once it has been written down.
     * Kept as an explicit action rather than clearing it on first view: the
     * window can be closed accidentally, and losing the only copy of the
     * admin password would mean reinstalling.
     */
    public function dismissCredentials()
    {
        @unlink(storage_path('first-run-credentials.txt'));

        return response()->json(['success' => true]);
    }

    /**
     * Live health of every background service, polled every few seconds by
     * the panel. Checks the actual TCP listener where possible rather than
     * just "did NativePHP think it spawned a process" — a spawned process
     * can still fail to bind its port (a conflict with something else on
     * the machine is the common case) and would otherwise report healthy.
     */
    public function status(
        NativeNetworkInfo $network,
        NativeEnvSettings $envSettings,
        PopplerBinary $poppler,
    ) {
        // Probe what the services were *started* with, not what .env says
        // now. After a save-without-restart those differ, and probing the
        // saved-but-not-yet-applied address reports a healthy Reverb as down.
        // See NativeAppServiceProvider::recordRunningConfig().
        $saved = $envSettings->get(['REVERB_PORT', 'LAN_SERVER_PORT']);
        $running = $this->runningConfig() ?? $saved;

        // Probed on the LAN address, not loopback.
        //
        // Loopback answers the wrong question. Reverb binds 0.0.0.0, but so
        // does anything else already on that port — and when Laragon's nginx
        // held 8080, 127.0.0.1:8080 answered, this panel went green, and
        // every tablet on the network got "403 Forbidden — nginx" instead of
        // live updates. The panel spent the whole day insisting the service
        // was fine. The address the tablets dial is the only one worth
        // checking, so that is the one checked.
        $reverbPort = (int) ($running['REVERB_PORT'] ?: 8080);
        $reverbHost = $network->lanIpAddresses()[0] ?? '127.0.0.1';
        $lanPort = (int) ($running['LAN_SERVER_PORT'] ?: 8000);

        $pendingRestart = collect($saved)
            ->reject(fn ($value, $key) => (string) $value === (string) ($running[$key] ?? $value))
            ->keys()
            ->all();

        // Ask the connection the app actually uses rather than probing a
        // host/port: under NativePHP this is always a local SQLite file
        // (the runtime forces database.default to its own 'nativephp'
        // connection), so "can we open it" is the only meaningful question.
        $databaseReachable = $this->databaseOk();

        // One round-trip to the NativePHP daemon for every process's status
        // instead of one per alias — each round-trip is a full HTTP request
        // to localhost, and doing four of them per poll is the main thing
        // that makes a panel like this feel sluggish.
        $processes = $this->runningProcesses();

        return response()->json([
            // Non-empty means saved settings are waiting on a restart, and
            // every reading below describes the old configuration.
            'pending_restart' => $pendingRestart,
            'database' => ['ok' => $databaseReachable],
            // `conflict` names the foreign server squatting the port, when
            // there is one. Naming it is the difference between "البث اللحظي
            // متوقف" — which sends someone hunting through this app — and
            // "nginx is on 8080", which tells them exactly what to close or
            // which port to change.
            'reverb' => [
                ...$this->reverbHealth($network, $reverbHost, $reverbPort),
                'process' => $this->processAlive($processes, 'reverb'),
                'host' => $reverbHost,
                'port' => $reverbPort,
            ],
            'queue' => [
                'process' => $this->processAlive($processes, 'queue_default'),
                'pending' => $databaseReachable ? $this->tableCount('jobs') : null,
                'failed' => $databaseReachable ? $this->tableCount('failed_jobs') : null,
            ],
            'scheduler' => ['process' => $this->processAlive($processes, 'scheduler')],
            // "This endpoint responded, so lan_server must be up" only holds
            // when the request actually arrived THROUGH lan_server. The
            // native control window is served by NativePHP's own internal
            // server instead (a different process on a different port), so
            // that assumption is simply false here — checking the port
            // directly is correct either way.
            'lan_server' => ['ok' => $network->portOpen('127.0.0.1', $lanPort), 'port' => $lanPort],
            // Whether anything in the building has ever actually got through.
            //
            // The row above proves only that the port is open *to this
            // machine*, and 127.0.0.1 always is. A dismissed Windows Defender
            // prompt on first launch leaves the LAN server listening and
            // unreachable from every tablet in the restaurant, with this
            // panel reporting it green — the failure it is least able to
            // afford. A request that arrived from another IP settles the
            // question outright, without asking a localised firewall a
            // question it answers unreliably. See LanReachability.
            'lan_reach' => app(LanReachability::class)->lastSeen(),
            'thermal' => [
                'ok' => $poppler->available(),
                'usb' => UsbPrinterTransport::supported(),
            ],
            'printers' => $databaseReachable ? $this->printerHealth() : null,
        ]);
    }

    public function update(Request $request, NativeEnvSettings $envSettings)
    {
        $validated = $request->validate([
            'reverb_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'lan_server_port' => ['required', 'integer', 'min:1', 'max:65535'],
            // Blank is meaningful: it hands the choice back to
            // PublicUrlResolver's LAN detection.
            'public_base_url' => ['nullable', 'string', 'max:255', 'url'],
        ]);

        $envSettings->set([
            'REVERB_PORT' => (string) $validated['reverb_port'],
            'LAN_SERVER_PORT' => (string) $validated['lan_server_port'],
            'PUBLIC_BASE_URL' => rtrim((string) ($validated['public_base_url'] ?? ''), '/'),

            // Not editable any more, and pinned on every save so an install
            // that was configured with a machine address before this became
            // automatic repairs itself.
            //
            // REVERB_HOST is only ever used by THIS app to publish events
            // into Reverb (config/broadcasting.php), and Reverb runs on this
            // same machine — so loopback is both correct and the only value
            // that keeps working when the router hands the machine a new IP.
            // Pointing it at the LAN address was the failure mode: a changed
            // DHCP lease silently killed every live update in the building.
            'REVERB_HOST' => '127.0.0.1',

            // VITE_* are compiled into the JS bundle at build time, so
            // rewriting them here changes nothing for the already-built
            // assets. Kept in step so a rebuild from this .env matches, and
            // deliberately left without a host: resources/js/echo.js dials
            // whatever host the page itself was loaded from, which is the
            // one address that is right for a waiter's tablet, the kitchen
            // screen and a customer's phone at the same time.
            'VITE_REVERB_HOST' => '',
            'VITE_REVERB_PORT' => (string) $validated['reverb_port'],
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Loads the cafe's catalog and staff on demand, from the button on the
     * panel.
     *
     * First-run seeding only fires on a database that has never been set up,
     * and an install that came up empty is past that point forever: the
     * tables exist, so the app treats it as already initialised on every
     * later launch. Recovering meant uninstalling, hunting down a hidden
     * application-data folder and deleting it by hand — with the app's own
     * data in it. This is the same seeder, reachable without any of that.
     *
     * Safe to press twice: the seeder inserts with insertOrIgnore, so rows
     * that already exist are left exactly as they are, including any price
     * or name the cafe has since corrected.
     */
    public function loadLegacyData()
    {
        try {
            Artisan::call('db:seed', [
                '--class' => LegacyOrangeDataSeeder::class,
                '--force' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('Legacy data seeding failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'تعذر تحميل البيانات: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'counts' => [
                'products' => $this->tableCount('products'),
                'categories' => $this->tableCount('categories'),
                'users' => $this->tableCount('users'),
            ],
        ]);
    }

    /**
     * Shuts the whole thing down — services first, then the app itself.
     *
     * The tray's own Quit relies on Electron killing its children, and
     * NativePHP's source is candid that it does not always manage it
     * ("Sometimes does not kill the subprocess of php server"). What
     * survives is `artisan serve` and `reverb:start` holding ports 8000 and
     * 8080, which is why the next launch appears to do nothing at all.
     *
     * Stopping the children through the daemon first — and waiting for the
     * ports to actually come free — leaves nothing behind to survive.
     */
    public function shutdown(NativeServices $services)
    {
        try {
            $services->stopAll();
        } catch (\Throwable $e) {
            Log::warning('Could not stop the services cleanly before quitting.', ['error' => $e->getMessage()]);
        }

        // Quitting kills the very server that has to deliver this reply,
        // so it is deferred to after the response has been sent — otherwise
        // the panel sits waiting on a request that can never arrive and
        // shows a failure for a shutdown that worked.
        app()->terminating(function () {
            try {
                NativeApp::quit();
            } catch (\Throwable $e) {
                Log::warning('Could not quit the app after stopping the services.', ['error' => $e->getMessage()]);
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * The tray's "إغلاق الخادم", which is how the app is actually closed.
     *
     * Identical work to shutdown() above — stop the children, then quit —
     * but reachable by navigation, because a tray menu item can only open a
     * URL, and the item there used to be NativePHP's built-in quit. That
     * quits Electron and leaves the LAN server, Reverb, the queue and the
     * scheduler running, so "closed" meant a `php -S` still holding port
     * 8000 and a next launch fighting its own corpse for it.
     *
     * It shows a plain page while it happens: the window is visibly on
     * screen for the second or two it takes, and a page of JSON is not an
     * answer to "close the server".
     */
    public function shutdownPage(NativeServices $services)
    {
        $this->shutdown($services);

        return response(
            '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
            .'<title>إغلاق الخادم</title><style>'
            .'body{font-family:system-ui,sans-serif;display:flex;align-items:center;'
            .'justify-content:center;height:100vh;margin:0;background:#f8fafc;color:#334155}'
            .'</style></head><body><p>جارٍ إيقاف الخدمات وإغلاق الخادم…</p></body></html>'
        );
    }

    /**
     * Writes a backup and reports where it landed.
     */
    public function exportBackup(BackupService $backups)
    {
        try {
            $path = $backups->export();

            return response()->json([
                'success' => true,
                'path' => $path,
                'size_mb' => round((filesize($path) ?: 0) / 1048576, 1),
            ]);
        } catch (\Throwable $e) {
            Log::error('Backup export failed', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Restores a backup over the live data, then brings the services back on
     * the restored database.
     *
     * The order matters and is the whole reason this is not just a file
     * copy: `artisan serve`, the queue worker and the scheduler all hold the
     * SQLite file open, and overwriting a file underneath a reader produces
     * "database disk image is malformed" rather than a restore.
     */
    public function importBackup(Request $request, BackupService $backups, NativeServices $services)
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        try {
            $services->stopAll();

            $tables = $backups->import($validated['path']);

            $services->startAll();
            $services->recordRunningConfig();

            return response()->json(['success' => true, 'tables' => $tables]);
        } catch (\Throwable $e) {
            Log::error('Backup import failed', ['error' => $e->getMessage()]);

            // Whatever went wrong, the cafe must not be left with its
            // services down on top of it.
            try {
                $services->startAll();
            } catch (\Throwable) {
                // Reported below either way.
            }

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Empties the print queue.
     *
     * A printer that is switched off does not lose its tickets: each job
     * retries every 30s for a full day (PrintOrderTicket::retryUntil), so a
     * till printer left off overnight comes back to hundreds of queued
     * receipts and prints the lot. By then they are history — the tables
     * have long since paid and gone — and someone has to stand there
     * tearing off paper while today's orders wait behind them.
     *
     * Clearing pending and failed jobs together, because a failed job is
     * just as stale: nothing here is worth printing late.
     */
    public function clearQueue()
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            DB::table('jobs')->delete();
            DB::table('failed_jobs')->delete();

            Log::info('Print queue cleared from the control panel.', [
                'pending' => $pending,
                'failed' => $failed,
            ]);

            return response()->json([
                'success' => true,
                'cleared' => ['pending' => $pending, 'failed' => $failed],
            ]);
        } catch (\Throwable $e) {
            Log::error('Could not clear the print queue', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => 'تعذر تفريغ الطابور.'], 500);
        }
    }

    /**
     * Copies text to the system clipboard through Electron.
     *
     * The panel's copy buttons try navigator.clipboard first, which fails in
     * this window: the Clipboard API needs a secure context, and the window
     * is served over plain http from NativePHP's internal server on a port
     * that varies per launch. So the LAN address — the one thing whoever
     * sets the cafe up has to get into a tablet — could only be transcribed
     * by hand, and the button just said "تعذر النسخ".
     */
    public function copy(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2048'],
        ]);

        try {
            Clipboard::text($validated['text']);
        } catch (\Throwable $e) {
            Log::error('Native clipboard write failed', ['error' => $e->getMessage()]);

            return response()->json(['success' => false], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Stops and re-spawns Reverb, the LAN server, the queue worker and the
     * scheduler so they pick up the .env that was just saved. Those four
     * processes are the only ones that read it.
     *
     * Deliberately a separate action from update() so saving settings never
     * interrupts service for the whole restaurant without someone choosing
     * that explicitly.
     *
     * This used to call App::relaunch(), which could not work from here on
     * two counts: NativePHP's /app/relaunch handler calls app.relaunch() and
     * app.quit() without ever sending a response, so this request died with
     * the process it had just asked to kill and the panel reported failure
     * whatever actually happened; and quitting a tray app is not a reliable
     * way to get it back. Nothing about restarting the children needs the
     * Electron process to go anywhere.
     */
    public function restart(Request $request, NativeServices $services)
    {
        try {
            $restarted = $services->restartAll();
        } catch (\Throwable $e) {
            Log::error('Native service restart failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'تعذر إعادة تشغيل الخدمات — أغلق التطبيق وشغّله يدوياً.',
            ], 500);
        }

        return response()->json(['success' => true, 'restarted' => $restarted]);
    }

    /**
     * The settings the currently-running child processes were started with,
     * as recorded at boot. Null outside the native runtime, or before the
     * first boot has recorded anything — callers fall back to .env.
     *
     * @return array<string, string>|null
     */
    /**
     * Reverb is only "ok" when the port answers AND the thing answering is
     * ours.
     *
     * A port answered by someone else is the worst of the three states: it
     * looks healthy from every angle except the one that matters, and
     * nobody debugging dead live-updates has any reason to suspect the port
     * itself. Naming the squatter turns a day of hunting into one glance.
     *
     * @return array{ok: bool, conflict: string|null}
     */
    private function reverbHealth(NativeNetworkInfo $network, string $host, int $port): array
    {
        $result = $network->identifyServer($host, $port);

        return [
            'ok' => $result['reachable'] && $result['conflict'] === null,
            'conflict' => $result['conflict'],
        ];
    }

    private function runningConfig(): ?array
    {
        try {
            $raw = NativeSettings::get('running_config');
        } catch (\Throwable) {
            return null;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function databaseOk(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableCount(string $table): ?int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A one-line summary of the printers the scheduled sweep
     * (app:check-printer-statuses) last reached. Printing is the part of
     * this app that most often breaks in a real kitchen — a printer gets
     * unplugged or its IP changes — and it is invisible from anywhere else
     * until someone notices tickets have stopped coming out.
     *
     * @return array{active: int, online: int}|null
     */
    private function printerHealth(): ?array
    {
        try {
            $active = Printer::query()->where('is_active', true)->get(['status']);

            return [
                'active' => $active->count(),
                'online' => $active->where('status', 'online')->count(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Aliases the NativePHP daemon has registered, read straight off its HTTP
     * API rather than through ChildProcess::all().
     *
     * all() hydrates every entry through ChildProcess::fromRuntimeProcess(),
     * which dereferences a `settings` key the daemon omits for already-running
     * processes — one such entry throws and takes the status of *every*
     * service down with it, so the panel showed four healthy services as
     * stopped. The raw response needs no hydration.
     *
     * Null means the daemon itself couldn't be reached (running outside the
     * native runtime, e.g. a plain `php artisan serve` for web-only
     * development); the panel renders that differently from a confirmed-down
     * process.
     *
     * @return string[]|null
     */
    private function runningProcesses(): ?array
    {
        try {
            $processes = app(Client::class)->get('child-process/')->json();

            return is_array($processes) ? array_keys($processes) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  string[]|null  $processes  aliases, or null if the daemon is unreachable
     */
    private function processAlive(?array $processes, string $alias): ?bool
    {
        if ($processes === null) {
            return null;
        }

        return in_array($alias, $processes, true);
    }
}
