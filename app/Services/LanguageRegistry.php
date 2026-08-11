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
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('languages')) {
                return collect();
            }

            return Language::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get();
        });
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
     * Resolve the best matching active language for a raw Accept-Language
     * header value, falling back to the default language.
     */
    public function matchAcceptLanguage(?string $header): Language
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
