<?php

namespace App\Services;

use App\Models\Language;
use Astrotomic\Translatable\Locales as TranslatableLocales;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for "which languages exist and which is default",
 * cached so every request doesn't hit the database. Every part of the app
 * (Astrotomic config, mcamara config, Inertia shared props, validation)
 * reads languages through here instead of querying the model directly, so
 * there is exactly one place to invalidate when a language changes.
 */
class LanguageRegistry
{
    public const CACHE_KEY = 'languages.active.v1';

    /**
     * @return Collection<int, Language>
     */
    public function active(): Collection
    {
        // Every read here is wrapped rather than just guarded by
        // Schema::hasTable(): hasTable() itself connects, so on a machine
        // where the database isn't reachable yet it throws instead of
        // returning false. That is not a hypothetical — this runs from
        // LocalizationServiceProvider::boot() on *every* request, including
        // the packaged app's very first boot (before migrate has created
        // the SQLite file) and the native control panel, whose entire job
        // is to be reachable and report that the database is down. An
        // exception here took the whole process out before either could.
        return $this->quietly(function () {
            if (! Schema::hasTable('cache')) {
                return $this->fetchActive();
            }

            $cached = Cache::get(self::CACHE_KEY);

            if ($cached instanceof Collection && $cached->isNotEmpty()) {
                return $cached;
            }

            $fresh = $this->fetchActive();

            // An empty result is never cached, which is why this is not the
            // one-liner Cache::rememberForever() would give.
            //
            // "No active languages" is always a transient, broken state: the
            // packaged app's very first boot runs before the seeder has
            // created any, and a database that briefly cannot be read yields
            // the same empty collection (see quietly()). rememberForever()
            // then pinned that emptiness in the cache table *permanently* —
            // and with no languages, direction() falls back to LTR, so every
            // page in the app rendered left-to-right no matter which language
            // was selected, and no amount of reseeding fixed it.
            if ($fresh->isNotEmpty()) {
                Cache::forever(self::CACHE_KEY, $fresh);
            }

            return $fresh;
        });
    }

    /**
     * @return Collection<int, Language>
     */
    private function fetchActive(): Collection
    {
        if (! Schema::hasTable('languages')) {
            return collect();
        }

        return Language::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  \Closure(): Collection<int, Language>  $read
     * @return Collection<int, Language>
     */
    private function quietly(\Closure $read): Collection
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            // Deliberately not Log::warning() — the logger itself may be
            // the thing that's unavailable (the `single`/`daily` channels
            // write to storage/logs, which is writable, but a database log
            // channel would recurse straight back into the failure).
            error_log('LanguageRegistry: language lookup failed, falling back to config defaults — '.$e->getMessage());

            return collect();
        }
    }

    public function default(): ?Language
    {
        return $this->active()->firstWhere('is_default', true) ?? $this->active()->first();
    }

    public function find(string $code): ?Language
    {
        return $this->active()->firstWhere('code', $code);
    }

    public function isActive(string $code): bool
    {
        return $this->find($code) !== null;
    }

    public function direction(string $code): string
    {
        return $this->find($code)?->direction ?? Language::DIRECTION_LTR;
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return $this->active()->pluck('code')->all();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Push the current `languages` table state into every package/config
     * that still expects a static locale list (Astrotomic\Translatable,
     * mcamara/laravel-localization, Laravel's own app.locale). Called once
     * at boot() for the normal request lifecycle, and again by
     * LanguageObserver whenever a language changes — a single PHP process
     * (queue worker, `db:seed`, Octane) can otherwise keep serving a config
     * snapshot from before that change.
     */
    public function syncRuntimeConfig(): void
    {
        $active = $this->active();

        if ($active->isEmpty()) {
            $this->applyFallbackLocale();

            return;
        }

        $default = $this->default();

        config([
            'translatable.locales' => $active->pluck('code')->all(),
            'translatable.fallback_locale' => $default?->code,

            'laravellocalization.supportedLocales' => $active->mapWithKeys(fn (Language $language) => [
                $language->code => [
                    'name' => $language->name,
                    'native' => $language->native_name,
                    'script' => $language->direction === Language::DIRECTION_RTL ? 'Arab' : 'Latn',
                    'regional' => '',
                    'dir' => $language->direction,
                ],
            ])->all(),
            'laravellocalization.localesOrder' => $active->pluck('code')->all(),

            'app.locale' => $default?->code ?? config('app.locale'),
            'app.fallback_locale' => $default?->code ?? config('app.fallback_locale'),
        ]);

        App::setLocale($default?->code ?? App::getLocale());

        // Astrotomic's Locales is a singleton that snapshots the config in
        // its constructor — if it was already resolved earlier in this
        // process, it needs to be told to re-read config explicitly.
        if (app()->resolved(TranslatableLocales::class)) {
            app(TranslatableLocales::class)->load();
        }
    }

    /**
     * Keep the app in a usable state when the `languages` table yields
     * nothing — a database that isn't ready yet (a packaged install's very
     * first boot, before app:first-run-setup has run) or one that can't be
     * read at all (see active()).
     *
     * config/translatable.php ships `'locales' => []` on purpose, because
     * the real list is database-driven. But Astrotomic treats an empty list
     * as a misconfiguration and throws LocalesNotDefinedException the moment
     * any translated model is touched — so returning early here does not
     * degrade gracefully, it just moves the failure somewhere less obvious
     * ("run vendor:publish and define the locales configuration", which is
     * not the actual problem at all).
     *
     * One locale from config is enough to keep translations resolving and
     * pages rendering until the real list is available.
     */
    private function applyFallbackLocale(): void
    {
        $locale = config('app.locale') ?: 'en';

        config([
            'translatable.locales' => [$locale],
            'translatable.fallback_locale' => $locale,
        ]);

        if (app()->resolved(TranslatableLocales::class)) {
            app(TranslatableLocales::class)->load();
        }
    }

    /**
     * Resolve the best matching active language for a raw Accept-Language
     * header value, falling back to the default language.
     *
     * Null when there are no active languages at all — a freshly installed
     * instance before `app:first-run-setup` has seeded them, or a moment
     * when the database can't be read (see active()). Callers fall back to
     * config('app.locale').
     */
    public function matchAcceptLanguage(?string $header): ?Language
    {
        $default = $this->default();

        if (! $header) {
            return $default;
        }

        $requested = collect(explode(',', $header))
            ->map(function (string $part) {
                [$code] = explode(';', trim($part));

                return strtolower(trim($code));
            });

        foreach ($requested as $code) {
            if ($language = $this->active()->first(fn (Language $l) => strtolower($l->code) === $code)) {
                return $language;
            }

            $base = explode('-', $code)[0];
            if ($language = $this->active()->first(fn (Language $l) => strtolower($l->code) === $base)) {
                return $language;
            }
        }

        return $default;
    }

    /**
     * Guard rails so a language is only ever deleted when doing so can't
     * strand data: never the default, never the last active language, and
     * never one that still has translated rows in a registered model.
     */
    public function assertDeletable(Language $language): void
    {
        if ($language->is_default) {
            throw ValidationException::withMessages([
                'language' => __('admin.languages.cannot_delete_default'),
            ]);
        }

        if ($this->active()->count() <= 1) {
            throw ValidationException::withMessages([
                'language' => __('admin.languages.cannot_delete_last_active'),
            ]);
        }

        foreach (config('translation.translatable_models', []) as $modelClass) {
            $translationClass = (new $modelClass)->getTranslationModelName();

            if ($translationClass::query()->where('locale', $language->code)->exists()) {
                throw ValidationException::withMessages([
                    'language' => __('admin.languages.cannot_delete_in_use', ['model' => $modelClass]),
                ]);
            }
        }
    }
}
