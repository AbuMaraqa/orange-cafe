<?php

namespace App\Providers;

use App\Services\Native\NativeEnvSettings;
use App\Services\Native\NativeNetworkInfo;
use App\Services\Native\PublicUrlResolver;
use App\Services\Printing\PopplerBinary;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // All four memoize a lookup that is comparatively expensive and
        // cannot change within a request: a filesystem probe for the poppler
        // binary, a line-by-line read of .env, an enumeration of the
        // machine's network interfaces. Bound as singletons so that work
        // happens once per request rather than once per injection point.
        $this->app->singleton(PopplerBinary::class);
        $this->app->singleton(NativeEnvSettings::class);
        $this->app->singleton(NativeNetworkInfo::class);
        $this->app->singleton(PublicUrlResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
