<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Replaces Laravel's `optimize` for this application.
 *
 * NativePHP runs `php artisan optimize` on *every* launch of the packaged
 * app (electron-plugin/src/server/php.ts), with a comment admitting it is a
 * workaround: "the cached config is not picked up on subsequent launches, so
 * we'll just rebuild it every time for now". Measured here, that is ~0.9s of
 * work — on the bundled static PHP, without a warm opcache, considerably
 * more — repeated at every start, rebuilding files identical to the ones
 * already sitting there.
 *
 * This command changes two things about it.
 *
 * 1. `route:cache` is never run. It cannot be: every customer-facing route
 *    lives in a group prefixed by LaravelLocalization::setLocale(), which
 *    resolves against the *current request*. Caching evaluates it once from
 *    the CLI, where there is no request, freezing one locale in as the only
 *    prefix that exists — after which /en/... 404s on every device in the
 *    building while /ar/... works. That bug shipped, and deleting the cache
 *    afterwards (NativeAppServiceProvider::discardCachedRoutes) is a
 *    rearguard action; not writing it is the fix.
 *
 * 2. The rest is skipped when it is already up to date. Config and views are
 *    cached from files that only change when a new version is installed, so
 *    rebuilding them on a launch where nothing changed buys nothing and
 *    costs the customer a slower start every single time.
 */
class OptimizeCommand extends Command
{
    protected $signature = 'optimize {--force : Rebuild the caches even when they are current}';

    protected $description = 'Cache the framework bootstrap files (routes excluded — see the class docblock)';

    public function handle(): int
    {
        if (! $this->option('force') && $this->cachesAreCurrent()) {
            $this->components->info('Caches are already current — nothing to rebuild.');

            return self::SUCCESS;
        }

        foreach (['config:cache' => 'config', 'view:cache' => 'views'] as $command => $label) {
            $this->components->task($label, fn () => $this->callSilently($command) === 0);
        }

        // Any route cache left over from a previous version of this app (or
        // from someone running `php artisan route:cache` by hand) has to go,
        // or it keeps serving the frozen single-locale routing table.
        $this->callSilently('route:clear');

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Whether the cached files are newer than everything they are built
     * from.
     *
     * .env is included deliberately: the app rewrites it at boot with this
     * install's own APP_KEY and REVERB_APP_SECRET, and a config cache older
     * than that rewrite is exactly the stale-secret bug that made every
     * broadcast fail with "Authentication signature invalid".
     */
    private function cachesAreCurrent(): bool
    {
        $configCache = $this->laravel->getCachedConfigPath();

        if (! is_file($configCache)) {
            return false;
        }

        if ($this->nativeRuntimeChanged($configCache)) {
            return false;
        }

        $builtAt = filemtime($configCache) ?: 0;

        foreach ($this->sourceFiles() as $file) {
            if ((filemtime($file) ?: 0) > $builtAt) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the cached config belongs to a different run of the desktop app.
     *
     * mtimes cannot see this. Electron generates a fresh secret and picks an
     * API port on every launch and passes both in the environment, so they
     * appear in no file and nothing's timestamp moves when they change. The
     * cache is then judged current, keeps the *previous* launch's secret, and
     * the running app authenticates with the new one — at which point
     * NativePHP's PreventRegularBrowserAccess middleware 403s every
     * /_native/* request.
     *
     * That includes the POST to /_native/api/booted that
     * NativeAppServiceProvider::boot() hangs off, so the app comes up with a
     * window and nothing behind it: no LAN server, no Reverb, no queue
     * worker, no scheduler — and a log holding one unexplained 403, because
     * the code that would have written anything else never ran.
     *
     * It survived because the launch that builds the cache always works; it
     * is the *next* one that cannot start, so a fresh install looks fine and
     * fails the second time it is opened.
     */
    private function nativeRuntimeChanged(string $configCache): bool
    {
        $live = [
            'secret' => env('NATIVEPHP_SECRET'),
            'api_url' => env('NATIVEPHP_API_URL'),
        ];

        // Not running under NativePHP — a plain CLI call, the test suite, a
        // web deployment — so there is nothing per-launch to go stale.
        if ($live['secret'] === null && $live['api_url'] === null) {
            return false;
        }

        try {
            $cached = require $configCache;
        } catch (Throwable) {
            return true;
        }

        $internal = is_array($cached) ? ($cached['nativephp-internal'] ?? []) : [];

        foreach ($live as $key => $value) {
            if ($value !== null && ($internal[$key] ?? null) !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (File::glob($this->laravel->configPath('*.php')) ?: [] as $file) {
            $files[] = $file;
        }

        foreach ([$this->laravel->basePath('.env'), $this->laravel->basePath('composer.lock')] as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
