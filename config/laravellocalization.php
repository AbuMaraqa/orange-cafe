<?php

return [

    // Bootstrap-only fallback: the package throws if this is empty, and
    // routes are loaded before the `languages` table necessarily exists
    // (e.g. during `migrate` on a fresh install). App\Providers\
    // LocalizationServiceProvider overwrites this from the `languages`
    // table on every request once the DB is ready — this static entry is
    // never what an actual request serves, so it doesn't need updating
    // when languages are added/removed from the admin panel.
    'supportedLocales' => [
        'ar' => ['name' => 'Arabic', 'script' => 'Arab', 'native' => 'العربية', 'regional' => '', 'dir' => 'rtl'],
    ],

    // Requires middleware `LocaleSessionRedirect`.
    // Automatically determine locale from browser (Accept-Language) on first
    // visit if it's not defined in the URL, for locales that are active.
    'useAcceptLanguageHeader' => true,

    // Keep locale segment even for the default language (e.g. always `/ar/...`)
    // so URLs are unambiguous and consistent across every active language.
    'hideDefaultLocaleInURL' => false,

    // Populated dynamically alongside supportedLocales (mirrors `sort_order`).
    'localesOrder' => [],

    'localesMapping' => [],

    'utf8suffix' => env('LARAVELLOCALIZATION_UTF8SUFFIX', '.UTF-8'),

    'urlsIgnored' => ['/up'],

    'httpMethodsIgnored' => ['POST', 'PUT', 'PATCH', 'DELETE'],
];
