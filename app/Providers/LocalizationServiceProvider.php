<?php

namespace App\Providers;

use App\Services\LanguageRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Bridges the database-driven `languages` table into every package/config
 * that still expects a static locale list: Astrotomic\Translatable,
 * mcamara/laravel-localization, and Laravel's own app.locale. Adding or
 * deactivating a language in the admin panel takes effect immediately for
 * every one of these, without touching source code — see
 * LanguageRegistry::syncRuntimeConfig(), which this just triggers at boot.
 */
class LocalizationServiceProvider extends ServiceProvider
{
    public function boot(LanguageRegistry $languages): void
    {
        $languages->syncRuntimeConfig();
    }
}
