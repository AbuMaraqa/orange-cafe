<?php

namespace App\Http\Controllers\Admin\Concerns;

/**
 * Every translatable admin resource (Product, Category, Menu, Tag,
 * ModifierGroup, ...) only requires a name in the default language —
 * other languages are optional in the form and in validation. But the
 * *_translations tables have a NOT NULL name column per locale, and
 * translatable models create a translation row for every locale key
 * present in the submitted data. Left as-is, saving with an English name
 * blank while Arabic is filled in throws a DB "name cannot be null"
 * error instead of actually saving — this backfills any blank
 * non-default name with the default locale's name before that happens,
 * so leaving a translation blank always means "same as the default
 * language", never a save failure.
 */
trait FillsMissingTranslations
{
    protected function fillMissingTranslations(array $translations, ?string $defaultLocale): array
    {
        $defaultName = $defaultLocale ? ($translations[$defaultLocale]['name'] ?? '') : '';

        foreach ($translations as $locale => $fields) {
            if ($locale !== $defaultLocale && trim($fields['name'] ?? '') === '') {
                $translations[$locale]['name'] = $defaultName;
            }
        }

        return $translations;
    }
}
