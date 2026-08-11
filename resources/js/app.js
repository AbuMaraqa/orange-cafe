import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { applyLocale, i18n } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

function syncDocumentDirection(locale) {
    if (!locale) {
        return;
    }

    document.documentElement.setAttribute('lang', locale.current);
    document.documentElement.setAttribute('dir', locale.direction);
}

// Every Inertia visit (including a language switch, which is just a normal
// visit to the same page under a different /{locale}/ URL) carries the
// resolved `locale` shared prop. Re-syncing here means the switch is a
// single SPA navigation — no full page reload needed.
router.on('navigate', (event) => {
    const locale = event.detail.page.props.locale;
    syncDocumentDirection(locale);
    applyLocale(locale.current);
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    async setup({ el, App, props, plugin }) {
        const initialLocale = props.initialPage.props.locale;
        syncDocumentDirection(initialLocale);
        await applyLocale(initialLocale.current);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
