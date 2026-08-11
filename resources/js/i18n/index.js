import { createI18n } from 'vue-i18n';

// One JSON bundle per UI locale, code-split by Vite so only the active
// language's strings are ever downloaded — adding a new language later
// just means dropping `resources/js/lang/{code}.json` next to these,
// nothing else in the bundle graph changes.
const localeModules = import.meta.glob('../lang/*.json');
const loaded = new Set();

export const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: 'ar',
    fallbackLocale: 'ar',
    messages: {},
});

export async function loadLocaleMessages(locale) {
    if (loaded.has(locale)) {
        return;
    }

    const importer = localeModules[`../lang/${locale}.json`];

    if (!importer) {
        // No bundled UI strings for this locale yet (e.g. a language the
        // admin just activated) — vue-i18n's fallbackLocale keeps the UI
        // readable until translations are added.
        return;
    }

    const module = await importer();
    i18n.global.setLocaleMessage(locale, module.default);
    loaded.add(locale);
}

export async function applyLocale(locale) {
    await loadLocaleMessages(locale);
    i18n.global.locale.value = locale;
}
