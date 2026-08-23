import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // Toggled manually via a `dark` class on <html> (see resources/js/composables/useTheme.js)
    // instead of following the OS preference unconditionally — users can override it.
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        // Plain .js files (e.g. composables/useOrderStatus.js) build class
        // strings dynamically too — without this glob Tailwind never scans
        // them, so every class that only appears in one (status badge
        // colors, for instance) silently never gets generated, even though
        // the class name still ends up correctly in the rendered HTML.
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Default (font_family = "system"): no external font, lean
                // on the OS's own UI font — already covers Arabic (Segoe UI
                // / system-ui / Noto Sans) and Latin, no network dependency.
                sans: [...defaultTheme.fontFamily.sans],
                // Opt-in brand fonts, chosen per-restaurant from Settings.
                tajawal: ['Tajawal', ...defaultTheme.fontFamily.sans],
                almarai: ['Almarai', ...defaultTheme.fontFamily.sans],
                'plex-arabic': ['IBM Plex Sans Arabic', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
