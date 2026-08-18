<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Services\LanguageRegistry;
use Astrotomic\Translatable\Locales as TranslatableLocales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * config/translatable.php ships an empty `locales` list because the real one
 * is database-driven. Astrotomic treats empty as a misconfiguration and
 * throws LocalesNotDefinedException as soon as any translated model is
 * touched — so every path where the languages table yields nothing has to
 * leave a usable locale behind.
 */
class LanguageRegistryFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_languages_table_still_leaves_a_usable_locale(): void
    {
        Language::query()->delete();
        app(LanguageRegistry::class)->flush();

        config(['translatable.locales' => []]);

        app(LanguageRegistry::class)->syncRuntimeConfig();

        $this->assertSame([config('app.locale')], config('translatable.locales'));
        $this->assertSame(config('app.locale'), config('translatable.fallback_locale'));
    }

    /**
     * The packaged app's first boot, and any moment the database is
     * unavailable: this must not throw, and must not leave the locale list
     * empty either.
     */
    public function test_an_unreadable_database_does_not_throw_and_still_sets_a_locale(): void
    {
        config(['translatable.locales' => []]);

        // Point the default connection at a directory, which cannot be opened
        // as a SQLite file — every query then fails the way a missing or
        // locked database file would.
        config(['database.connections.broken' => [
            'driver' => 'sqlite',
            'database' => __DIR__,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('broken');

        try {
            app(LanguageRegistry::class)->syncRuntimeConfig();

            $this->assertNotEmpty(config('translatable.locales'));
        } finally {
            // Restored before teardown: RefreshDatabase rolls back its
            // transaction on the *default* connection, and leaving the broken
            // one in place fails every later test in the class.
            DB::setDefaultConnection($original);
            DB::purge('broken');
        }
    }

    public function test_a_populated_table_still_wins(): void
    {
        app(LanguageRegistry::class)->flush();

        Language::query()->delete();
        Language::create([
            'code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية',
            'direction' => Language::DIRECTION_RTL, 'is_active' => true,
            'is_default' => true, 'sort_order' => 0,
        ]);

        app(LanguageRegistry::class)->flush();
        app(LanguageRegistry::class)->syncRuntimeConfig();

        $this->assertSame(['ar'], config('translatable.locales'));
        $this->assertSame('ar', app(TranslatableLocales::class)->current());
    }
}
