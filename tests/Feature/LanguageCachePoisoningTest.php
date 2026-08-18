<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Services\LanguageRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The bug this exists for: active() used Cache::rememberForever(), which
 * happily cached an *empty* language list. A packaged install's first boot
 * always has such a window — the app serves requests before the seeder has
 * created any language — and once that emptiness was cached, it never
 * expired. With no languages, direction() falls back to LTR, so every page
 * rendered left-to-right whatever language was selected, and reseeding did
 * not fix it because nothing ever re-read the table.
 */
class LanguageCachePoisoningTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): LanguageRegistry
    {
        return app(LanguageRegistry::class);
    }

    private function createArabic(): void
    {
        Language::create([
            'code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية',
            'direction' => Language::DIRECTION_RTL, 'is_active' => true,
            'is_default' => true, 'sort_order' => 0,
        ]);
    }

    public function test_an_empty_language_list_is_never_cached(): void
    {
        Language::query()->delete();
        $this->registry()->flush();

        $this->assertTrue($this->registry()->active()->isEmpty());
        $this->assertNull(Cache::get(LanguageRegistry::CACHE_KEY), 'an empty list must not be cached');
    }

    public function test_direction_recovers_once_languages_are_seeded(): void
    {
        Language::query()->delete();
        $this->registry()->flush();

        // The first-boot window: something asks for the direction before any
        // language exists. Under the old behaviour this poisoned the cache.
        $this->assertSame('ltr', $this->registry()->direction('ar'));

        $this->createArabic();

        // Deliberately no flush() — the whole point is that it heals by
        // itself, because the empty result was never stored.
        $this->assertSame('rtl', $this->registry()->direction('ar'));
    }

    public function test_a_populated_list_is_still_cached(): void
    {
        Language::query()->delete();
        $this->createArabic();
        $this->registry()->flush();

        $this->registry()->active();

        $cached = Cache::get(LanguageRegistry::CACHE_KEY);

        $this->assertNotNull($cached, 'a real list should be cached');
        $this->assertCount(1, $cached);
    }

    public function test_the_rendered_page_is_rtl_for_arabic(): void
    {
        Language::query()->delete();
        $this->createArabic();
        $this->registry()->flush();
        $this->registry()->syncRuntimeConfig();

        $this->assertSame('rtl', $this->registry()->direction('ar'));
    }
}
