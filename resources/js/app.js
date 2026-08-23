import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { applyLocale, i18n } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Shows the failure instead of a white screen.
 *
 * Everything below runs before `mount()`: resolving the page component,
 * fetching the locale bundle. If any of it throws, Vue never mounts and the
 * page is left blank — no message, no reload button, nothing in the
 * interface that says anything went wrong. On a tablet propped at a table
 * that is indistinguishable from the app being broken beyond repair, and
 * the customer's only move is to put it down.
 *
 * So a failure gets a plain, readable panel with the reason on it. It is
 * also the only way the reason ever reaches a person: there is no console
 * on an iPad, and nothing here is reported to the server.
 */
function showBootFailure(reason) {
    const el = document.getElementById('app');

    if (!el || el.dataset.bootFailed === 'true' || el.childElementCount > 0) {
        return;
    }

    el.dataset.bootFailed = 'true';
    el.setAttribute('style', 'display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;font-family:system-ui,sans-serif;');
    el.innerHTML =
        '<div style="max-width:32rem;text-align:center;color:#1f2937">' +
        '<p style="font-size:1.125rem;font-weight:600;margin:0 0 8px">تعذّر تحميل الصفحة</p>' +
        '<p style="font-size:.875rem;color:#6b7280;margin:0 0 16px">Could not load the page</p>' +
        '<pre style="text-align:start;direction:ltr;white-space:pre-wrap;word-break:break-word;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;font-size:.75rem;color:#b91c1c;margin:0 0 16px">' +
        String(reason).replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c]) +
        '</pre>' +
        '<button onclick="location.reload()" style="background:#111827;color:#fff;border:0;border-radius:8px;padding:10px 20px;font-size:.875rem;font-weight:600;cursor:pointer">إعادة المحاولة</button>' +
        '</div>';
}

window.addEventListener('error', (event) => showBootFailure(event.error?.stack || event.message));
window.addEventListener('unhandledrejection', (event) => showBootFailure(event.reason?.stack || event.reason));

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

        try {
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .use(ZiggyVue)
                .use(i18n)
                .mount(el);
        } catch (error) {
            showBootFailure(error?.stack || error);

            throw error;
        }
    },
    progress: {
        color: '#4B5563',
    },
});
