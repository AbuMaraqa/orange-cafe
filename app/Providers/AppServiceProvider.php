<?php

namespace App\Providers;

use App\Listeners\OpenControlPanelOnTrayClick;
use App\Services\Native\NativeEnvSettings;
use App\Services\Native\NativeNetworkInfo;
use App\Services\Native\PublicUrlResolver;
use App\Services\Printing\PopplerBinary;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Native\Laravel\Events\MenuBar\MenuBarClicked;

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

        // The only way back into a running app. Double-clicking the desktop
        // icon cannot do it: NativePHP quits the second instance on the
        // single-instance lock, and the handler that should focus the
        // existing window checks a `mainWindow` property that is declared
        // null and never assigned. See OpenControlPanelOnTrayClick.
        Event::listen(MenuBarClicked::class, OpenControlPanelOnTrayClick::class);
    }
}
