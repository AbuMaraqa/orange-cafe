<!DOCTYPE html>
@php
    $branding = app(\App\Settings\RestaurantSettings::class);
    $fontClass = match ($branding->font_family) {
        'almarai' => 'font-almarai',
        'tajawal' => 'font-tajawal',
        'plex-arabic' => 'font-plex-arabic',
        default => 'font-sans',
    };

    /*
     * The customer-facing menu is light, always.
     *
     * It is a printed menu that happens to be on glass: it carries the
     * restaurant's photography and its two brand colours, and both were
     * chosen against white. A phone set to dark mode was recolouring all of
     * it — washed-out food photos on charcoal, brand colours at the wrong
     * contrast — for a page a customer looks at for two minutes while
     * deciding what to eat. Staff screens keep the choice; those are looked
     * at for eight hours in a room whose lighting the cafe controls.
     */
    $isCustomerPage = str_starts_with($page['component'] ?? '', 'Customer/');
@endphp
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ app(\App\Services\LanguageRegistry::class)->direction(app()->getLocale()) }}"
    style="--brand-primary: {{ $branding->primary_color }}; --brand-secondary: {{ $branding->secondary_color }};"
    @if ($isCustomerPage) data-force-light="true" @endif
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{--
            Home-screen install, for the tablets on the floor.

            `apple-mobile-web-app-capable` is the one that matters on an
            iPad: added to the home screen, the app opens with no address
            bar and no toolbars, so a waiter cannot mis-tap their way out of
            the floor plan and back into Safari. It works over plain http,
            which this app has to, being reached at http://<lan-ip>:8000.

            The status bar style is left as `default` on purpose. The
            alternative overlays the page under the clock, which then needs
            safe-area insets on everything fixed — the flash toast, the
            pending-orders dock — for no gain on a screen that is not a
            phone.

            There is no service worker and so no offline mode: browsers
            refuse to register one outside a secure context, and an IP
            address over http is not one. See WebManifestController.
        --}}
        <link rel="manifest" href="{{ route('manifest') }}">
        <meta name="theme-color" content="{{ $branding->primary_color }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ $branding->name }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/icon-192.png') }}">
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{--
            Reverb connection details, served fresh on every page load.

            Vite compiles VITE_REVERB_* into the JS bundle at build time, which
            makes them wrong for a desktop install: the server's port is
            editable from the native control panel, and one saved change there
            would leave every browser in the restaurant dialling the port that
            happened to be set on the *developer's* machine — live order
            updates silently dead, with nothing in the UI to say so.

            The host is omitted on purpose: resources/js/echo.js derives it
            from window.location.hostname, which is already correct for every
            device without the server having to guess its own address.
        --}}
        <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        <meta name="reverb-port" content="{{ config('broadcasting.connections.reverb.options.port') }}">
        <meta name="reverb-scheme" content="{{ config('broadcasting.connections.reverb.options.scheme') }}">

        <script>
            // Must run before first paint (hence: inline, in <head>, not
            // bundled) so the page never flashes the wrong theme.
            // Kept in sync with resources/js/composables/useTheme.js.
            (function () {
                try {
                    var root = document.documentElement;
                    // A customer page is light whatever the phone prefers.
                    if (root.dataset.forceLight === 'true') {
                        root.classList.remove('dark');
                    } else {
                        var stored = localStorage.getItem('orange.theme');
                        var isDark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                        root.classList.toggle('dark', isDark);
                    }
                } catch (e) {}
            })();
        </script>

        {{--
            No font links here any more.

            These were three tags pointing at fonts.googleapis.com, which
            made rendering the restaurant's own name depend on the public
            internet — on a machine that sits in a cafe serving tablets over
            its own LAN. No connection meant every screen fell back to a
            system font, and there was nothing in the interface to say why.

            The faces are bundled instead (resources/css/fonts.css, files in
            public/fonts) and arrive with the stylesheet Vite already emits.
            Nothing in this app reaches the internet now.
        --}}

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="{{ $fontClass }} antialiased">
        @inertia
    </body>
</html>
