<?php

namespace App\Http\Controllers;

use App\Models\Printer;
use App\Services\Native\NativeEnvSettings;
use App\Services\Native\NativeNetworkInfo;
use App\Services\Native\NativeServices;
use App\Services\Native\PublicUrlResolver;
use App\Services\Printing\PopplerBinary;
use App\Services\Printing\UsbPrinterTransport;
use Illuminate\Http\Request;
use Database\Seeders\LegacyOrangeDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Native\Laravel\Client\Client;
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

        // Always loopback: Reverb binds 0.0.0.0 on this machine, so this asks
        // "did it come up here" — the only question this panel can answer.
        // Which address the tablets dial is decided by their own page's host.
        $reverbHost = '127.0.0.1';
        $reverbPort = (int) ($running['REVERB_PORT'] ?: 8080);
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
            'reverb' => [
                'ok' => $network->portOpen($reverbHost, $reverbPort),
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
