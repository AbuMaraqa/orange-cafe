import { ref, watch } from 'vue';

const STORAGE_KEY = 'orange.theme';

function systemPrefersDark() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function readInitial() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'light' || stored === 'dark') return stored;
    } catch {
        // Storage unavailable (private browsing) — fall through to system preference.
    }

    return systemPrefersDark() ? 'dark' : 'light';
}

function applyTheme(value) {
    document.documentElement.classList.toggle('dark', value === 'dark');
}

// Module-level singleton: one shared theme state for the whole app, kept in
// sync with the inline blocking script in app.blade.php that sets the
// `dark` class before first paint (avoids a flash of the wrong theme).
const theme = ref(readInitial());
applyTheme(theme.value);

watch(theme, (value) => {
    applyTheme(value);
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // Not persisted this session — not worth surfacing an error for.
    }
});

export function useTheme() {
    function toggle() {
        theme.value = theme.value === 'dark' ? 'light' : 'dark';
    }

    function setTheme(value) {
        theme.value = value;
    }

    return { theme, toggle, setTheme };
}
