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
            },
        },
    },

    plugins: [forms],
};
