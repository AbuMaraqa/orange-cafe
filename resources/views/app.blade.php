<!DOCTYPE html>
@php
    $branding = app(\App\Settings\RestaurantSettings::class);
    $fontClass = match ($branding->font_family) {
        'almarai' => 'font-almarai',
        'tajawal' => 'font-tajawal',
        default => 'font-sans',
    };
@endphp
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ app(\App\Services\LanguageRegistry::class)->direction(app()->getLocale()) }}"
    style="--brand-primary: {{ $branding->primary_color }}; --brand-secondary: {{ $branding->secondary_color }};"
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <script>
            // Must run before first paint (hence: inline, in <head>, not
            // bundled) so the page never flashes the wrong theme.
            // Kept in sync with resources/js/composables/useTheme.js.
            (function () {
                try {
                    var stored = localStorage.getItem('orange.theme');
                    var isDark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', isDark);
                } catch (e) {}
            })();
        </script>

        @if ($branding->font_family !== 'system')
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
        @endif

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="{{ $fontClass }} antialiased">
        @inertia
    </body>
</html>
