<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Native\InstallIdentity;
use App\Services\Native\NativeNetworkInfo;
use App\Services\Native\NativeServices;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\App;
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
     * The screen each role actually works on, keyed by path.
     *
     * Locale-prefixed on purpose: every customer- and staff-facing route
     * lives under /{locale}/ (routes/web.php), so a bare /cashier is a 404.
     *
     * @var array<string, string>
     */
    public const ROLE_LINKS = [
        '/ar/admin' => 'الإدارة',
        '/ar/cashier' => 'الكاشير',
        '/ar/waiter' => 'النادل',
        '/ar/kitchen' => 'المطبخ',
    ];

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

        // Before anything else, and before any child process is spawned.
        $this->discardCachedRoutes();

        $this->ensureDeviceId();

        // Before any child process is spawned: they read .env at their own
        // startup, so what this writes is what they will use. The parent
        // keeps the shipped values in memory for the rest of this boot, which
        // is harmless — only the control-panel routes run here, and they
        // neither use sessions (see routes/web.php) nor broadcast.
        $rewrittenSecrets = app(InstallIdentity::class)->ensure();

        // The secrets just written to .env are invisible to anything reading
        // the cached config, which is everything: `artisan optimize` ran
        // before this boot hook and Laravel stops loading .env once
        // bootstrap/cache/config.php exists.
        //
        // Left alone, this app publishes order events signed with the stale
        // (shipped, empty) REVERB_APP_SECRET while the Reverb process below
        // starts with the new one from .env. Every broadcast is then rejected
        // with "Pusher error: Authentication signature invalid" — 2908 of them
        // in one packaged run — and live updates die silently: the kitchen
        // screen simply never hears about a new order, while every page still
        // loads normally and nothing in the UI suggests a problem.
        if ($rewrittenSecrets !== []) {
            $this->discardCachedConfig();
        }

        $this->ensureDatabaseIsReady();
        $this->openControlWindow();

        // No database gate around the services below, on purpose. NativePHP
        // forces database.default to its own 'nativephp' SQLite connection
        // (NativeServiceProvider::rewriteDatabase) — a local file that is
        // open by the time this runs, with nothing to wait for and no
        // network dependency that could make waiting worthwhile.
        //
        // Spawning lives in NativeServices because the control panel's
        // restart button has to repeat it with the settings just saved.
        $services = app(NativeServices::class);
        $services->startAll();

        if ((bool) env('NATIVE_START_AT_LOGIN', true)) {
            App::openAtLogin(true);
        }

        $port = (int) env('LAN_SERVER_PORT', 8000);
        $lanUrls = array_map(
            fn (string $ip) => "http://{$ip}:{$port}",
            app(NativeNetworkInfo::class)->lanIpAddresses()
        );

        $this->showTray("Orange Cafe — يعمل على المنفذ {$port}", $lanUrls);

        $services->recordRunningConfig();

        Log::info('NativeAppServiceProvider::boot() finished.');
    }

    /**
     * Drops the cached config so the freshly written secrets take effect.
     *
     * Only called when InstallIdentity actually changed something, which is
     * the first launch on a machine (and any launch where the stored values
     * drifted from .env). The cost is that this launch resolves config from
     * .env on every request instead of from the cache; the next launch's
     * `artisan optimize` rebuilds it, by then from a .env that already holds
     * the right values.
     */
    private function discardCachedConfig(): void
    {
        try {
            $cached = app()->bootstrapPath('cache/config.php');

            if (is_file($cached) && @unlink($cached)) {
                Log::info('Discarded the cached config so the new install secrets apply.', ['file' => $cached]);
            }
        } catch (Throwable $e) {
            Log::warning('Could not discard the cached config.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Deletes the route cache that NativePHP creates on every launch.
     *
     * The packaged app runs `php artisan optimize` each time it starts
     * (electron-plugin/src/server/php.ts — shouldOptimize() returns true for
     * anything that is not NODE_ENV=development), and optimize includes
     * `route:cache`.
     *
     * That is fatal here. Every customer-facing route lives in a group whose
     * prefix is `LaravelLocalization::setLocale()` (routes/web.php), and that
     * resolves the locale from the *current request*. Caching evaluates it
     * exactly once, from the CLI, where there is no request — so the default
     * locale is frozen into the cached file as the only prefix that exists.
     * On the customer's machine `/ar/...` works and every `/en/...` URL
     * returns 404: the menu, the kitchen screen and the waiter tablet all
     * break for anyone using English, with nothing in the logs to explain it.
     *
     * Nothing in development ever shows this, because development never runs
     * optimize — which is exactly why it reached a customer.
     *
     * Deleting rather than preventing: `optimize` has already run by the time
     * any PHP of ours executes. Doing it here, at the top of boot(), means the
     * LAN server, the queue worker and the scheduler are all spawned
     * afterwards and none of them ever sees the file. The config cache is left
     * alone — that one is wanted (see NativeEnvSettings).
     */
    private function discardCachedRoutes(): void
    {
        try {
            foreach ((array) glob(app()->bootstrapPath('cache/routes-*.php')) as $cached) {
                if (is_file($cached) && @unlink($cached)) {
                    Log::info('Discarded the cached route file so locale prefixes stay dynamic.', ['file' => $cached]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Could not discard the cached routes.', ['error' => $e->getMessage()]);
        }
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

        // The address other devices must use, falling back to loopback when
        // no LAN address could be detected (a machine with Wi-Fi switched
        // off, typically) — a tray menu that silently loses half its entries
        // is worse than one pointing at an address that only works here.
        $base = $lanUrls[0] ?? "http://127.0.0.1:{$port}";

        $items = [
            Menu::label($status),
            Menu::separator(),
            Menu::link($base.'/native/panel', 'صفحة الإعداد (هذا الجهاز)')->openInBrowser(true),
            Menu::separator(),
            Menu::label('روابط الأجهزة'),
        ];

        // One entry per role rather than a single root link: each screen is
        // opened on a different device by a different person, and asking a
        // waiter to find their own screen from the home page is a step that
        // gets skipped or got wrong on every new tablet.
        foreach (self::ROLE_LINKS as $path => $label) {
            $items[] = Menu::link($base.$path, $label)->openInBrowser(true);
        }

        // Every detected address, for a machine on more than one network.
        if (count($lanUrls) > 1) {
            $items[] = Menu::separator();

            foreach ($lanUrls as $url) {
                $items[] = Menu::link($url, "عنوان آخر: {$url}")->openInBrowser(true);
            }
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
