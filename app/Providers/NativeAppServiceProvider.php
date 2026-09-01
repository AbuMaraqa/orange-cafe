<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Native\InstallIdentity;
use App\Services\Native\NativeNetworkInfo;
use App\Services\Native\NativeServices;
use Illuminate\Filesystem\Filesystem;
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
use Native\Laravel\Menu\Items\MenuItem;
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
        $this->linkPublicStorage();
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

            // Every boot, not only the first. Migrations carry the schema
            // forward on an upgrade; nothing carried the *rows* the app
            // needs, because the only thing that seeded them ran solely when
            // there was no administrator — and reinstalling over real data
            // is not a first run.
            //
            // That is how a finished, tested accounting module ended up
            // returning 403 on every screen of every installation: its
            // permissions are seeded rows, and no upgrade path delivered
            // them. See SyncReferenceDataCommand.
            Artisan::call('app:sync-reference-data');

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
     * Points `public/storage` at the storage disk, so the LAN server hands
     * out menu photos itself instead of booting Laravel once per picture.
     *
     * `artisan serve` is `php -S`, and its router script returns false —
     * "serve this one yourself" — for any URI that exists as a real file
     * under the public directory. Uploaded images never do here: NativePHP
     * moves storage to a per-user application-data directory at boot, so
     * nothing under public/ points at them and every photo falls through to
     * StorageFileController, which is a full framework boot to hand over a
     * file already sitting on disk.
     *
     * Measured on a development machine, which is faster than the one in the
     * cafe: 1.8ms for the built-in server to send a 250KB file, against 46ms
     * for the cheapest Laravel request that exists (`/up`). Twenty-six times,
     * per photo.
     *
     * That multiplies rather than adds, because the server answers one
     * request at a time: PHP's built-in server is single-threaded, and
     * PHP_CLI_SERVER_WORKERS needs fork(), so on Windows there is no pool to
     * configure — setting it changes nothing at all (measured: six concurrent
     * menu loads still arrive 477/761/1020/1281/1548/1843ms, a flat queue).
     * A customer opening the menu therefore does not merely wait for their
     * own forty photos; they hold the kitchen screen and every waiter tablet
     * behind them for the duration. Forty images is ~1.8s of blocked server
     * through the controller, against ~70ms served directly.
     *
     * None of this is load-bearing. StorageFileController stays exactly where
     * it is and still answers whenever the link is missing or a file is not
     * behind it, which is what makes this safe to attempt and safe to fail.
     */
    private function linkPublicStorage(): void
    {
        try {
            $link = public_path('storage');
            $target = storage_path('app/public');

            // A fresh install has had nothing uploaded to it yet, and
            // `mklink /J` refuses a target that does not exist.
            if (! is_dir($target) && ! @mkdir($target, 0755, true) && ! is_dir($target)) {
                Log::warning('Could not create the public storage directory.', ['path' => $target]);

                return;
            }

            clearstatcache(true, $link);

            if (realpath($link) === realpath($target)) {
                return;
            }

            if (file_exists($link) && ! $this->removeStaleStorageLink($link)) {
                return;
            }

            // Not Artisan::call('storage:link'). That command reads its pairs
            // from config('filesystems.links'), whose keys were produced by
            // public_path()/storage_path() at the moment the config cache was
            // built — and this app runs on a cached config it did not
            // necessarily build here. Resolving both ends now, in the process
            // that has the relocated storage path, is the only version that
            // cannot be handed a developer's directory. Filesystem::link()
            // is the same call the command would end up making.
            app(Filesystem::class)->link($target, $link);

            clearstatcache(true, $link);

            if (realpath($link) === realpath($target)) {
                Log::info('Linked public/storage — the LAN server now serves uploads without booting Laravel.', [
                    'link' => $link,
                    'target' => $target,
                ]);

                return;
            }

            Log::warning('Could not link public/storage; uploads will be served through StorageFileController.', [
                'link' => $link,
                'target' => $target,
            ]);
        } catch (Throwable $e) {
            // An optimisation is never worth a failed boot. Everything still
            // works without it, only more slowly.
            Log::warning('Could not link public/storage.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clears a `public/storage` that points somewhere other than the storage
     * disk, and reports whether the path is now free.
     *
     * Only ever a link. A *real* directory there is left strictly alone: it
     * would be one the build accidentally packaged, holding the developer's
     * own photos, and deleting a directory tree to make room for a
     * performance optimisation is not a trade this is allowed to make. The
     * app is already correct without it — every file that stale copy lacks
     * falls through to StorageFileController, which reads the real disk.
     *
     * is_link() cannot tell the two apart, which is the whole reason this is
     * written out rather than left to `storage:link --force`: on Windows a
     * junction reports is_link() === false (measured), so that command's own
     * --force check never fires and it skips the repair with "the link
     * already exists". filetype() does distinguish them — a reparse point
     * reads as 'unknown' where a real directory reads as 'dir'.
     *
     * Unlinking a junction leaves the target and its contents untouched;
     * that was verified on a junction holding a file before this was written,
     * because getting it wrong deletes the cafe's menu photography.
     */
    private function removeStaleStorageLink(string $link): bool
    {
        if (! is_link($link) && @filetype($link) !== 'unknown') {
            Log::warning('public/storage is a real directory rather than a link — leaving it alone.', ['path' => $link]);

            return false;
        }

        // rmdir for a Windows junction or a directory symlink; unlink for a
        // POSIX symlink, where rmdir refuses the link itself.
        if (! @rmdir($link) && ! @unlink($link)) {
            Log::warning('Could not remove the stale public/storage link.', ['path' => $link]);

            return false;
        }

        return true;
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

        // Not Menu::quit(). That is Electron's own quit, and it takes the
        // window with it while the LAN server, Reverb, the queue and the
        // scheduler keep running — NativePHP's source is candid about why
        // ("Sometimes does not kill the subprocess of php server"). Closing
        // from the tray therefore left a `php -S` holding port 8000 with
        // the app apparently shut down, and the next launch had to fight
        // its own leftovers for the port. That is the whole of "sometimes
        // it starts and sometimes it doesn't".
        //
        // This route stops the children first and quits afterwards; see
        // NativeSettingsController::shutdownPage().
        $items[] = Menu::route('native.panel.shutdown-page', 'إغلاق الخادم');

        // Wrapped, because this is the last thing boot() does and it had no
        // guard: a tray that failed to appear took the rest of boot() with
        // it — including recordRunningConfig() — and left nothing anywhere
        // saying why. The app kept serving, so it looked like the icon had
        // simply not been drawn.
        //
        // The tray is also the only visible way back into the control
        // panel, so its absence has to be findable in the log rather than
        // guessed at from another machine.
        try {
            $this->buildTray($items);
        } catch (Throwable $e) {
            Log::error('Failed to create the tray icon — the control panel is still reachable at /native/panel.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  MenuItem[]  $items
     */
    private function buildTray(array $items): void
    {
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
            // order can genuinely take a few seconds, and the panel's
            // restart, legacy-import and backup routes need considerably
            // more than that.
            //
            // This reaches the window server and every child process spawned
            // through ChildProcess, but NOT the LAN server the tablets
            // actually use: `artisan serve` starts `php -S` as a separate
            // process and forwards no -d at all (ServeCommand::serverCommand),
            // so that one runs on the cli-server defaults — 30s and 128M —
            // whatever is set here. A slow request there has to raise its own
            // limits; editing this array will not do it.
            //
            // And on a process that never returns, a time limit is not a
            // safety net but a kill switch, so the four background services
            // override it back to 0 — see NativeServices::DAEMON_INI.
            'max_execution_time' => '120',

            // Without these the packaged app randomly comes up with no
            // window at all, and nothing anywhere says why.
            //
            // The bundled php.exe ships no php.ini, so it runs on built-in
            // defaults — which are opcache.enable=On with opcache.file_cache
            // unset. On Windows OPcache must map its opcode handlers at a
            // fixed base address in every process; when ASLR happens to have
            // put something else there, PHP aborts during module startup with
            // "Opcode handlers are unusable due to ASLR" / "Attempt to access
            // invalid address" (Application event log, source "Zend OPcache")
            // and the process is simply gone — no PHP error, no exit message.
            //
            // Which process dies is what makes it so confusing:
            // opcache.enable_cli is Off, so `artisan native:config` survives
            // and Electron happily gets its config. It is the PHP *server*
            // (SAPI cli-server, where opcache.enable does apply) that
            // vanishes. NativePHP only calls window.show() once that server
            // POSTs back to /_native/api/booted, so the app is left as a live
            // Electron process listening on port 4000, waiting forever for a
            // callback from a server that is no longer running. No window, no
            // tray icon, and an empty log because boot() never ran.
            //
            // It is a random address-space collision, so it reproduces on
            // roughly one launch in three and the launches that work prove
            // nothing — which is exactly how it survived nine builds.
            // file_cache_fallback is already On by default, but it is inert
            // while file_cache has no directory to fall back *to*; giving it
            // one turns the crash into a slightly slower cold start.
            'opcache.file_cache' => $this->opcacheFileCache(),
            'opcache.file_cache_fallback' => '1',

            // opcache.validate_timestamps stays ON. It was set to 0 once, as
            // a ~9% saving on the per-request stat() of every included file,
            // and it broke the app outright — the symptom being a window
            // that opens with nothing behind it and a log holding a single
            // unexplained 403.
            //
            // The two settings above are why. file_cache persists compiled
            // scripts to disk *between launches*, and with validation off
            // nothing ever reconsiders them. So the compiled
            // bootstrap/cache/config.php from the previous launch is served
            // to the next one, carrying that launch's NATIVEPHP_SECRET —
            // and Electron generates a fresh secret on every start. The
            // running app then authenticates with a secret the cached
            // config has never heard of, PreventRegularBrowserAccess 403s
            // every /_native/* request including the POST to
            // /_native/api/booted, and boot() never runs: no LAN server, no
            // Reverb, no queue worker, no scheduler.
            //
            // That is precisely the failure OptimizeCommand::
            // nativeRuntimeChanged() exists to catch. It rebuilds the config
            // cache when the secret moves — and rebuilding the *file* is
            // useless if OPcache has been told never to look at it again.
            // The saving is not worth defeating that.
        ];
    }

    /**
     * A writable directory for OPcache's file cache, created if missing.
     *
     * Under storage/ because that is the tree NativePHP repoints at a
     * per-user writable location on a packaged install (it passes
     * LARAVEL_STORAGE_PATH=%APPDATA%\orange-cafe\storage). The app
     * directory itself has to be treated as read-only, and OPcache quietly
     * ignores a file_cache path it cannot write — which would put us straight
     * back to the crash this exists to prevent.
     */
    private function opcacheFileCache(): string
    {
        $path = storage_path('framework/opcache');

        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        return $path;
    }
}
