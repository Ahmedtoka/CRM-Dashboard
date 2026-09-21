import '@fontsource/ibm-plex-sans-arabic/latin-400.css';
import '@fontsource/ibm-plex-sans-arabic/latin-500.css';
import '@fontsource/ibm-plex-sans-arabic/latin-600.css';
import '@fontsource/ibm-plex-sans-arabic/latin-700.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-400.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-500.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-600.css';
import '@fontsource/ibm-plex-sans-arabic/arabic-700.css';
import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import TopLoadingBar from './components/crm/TopLoadingBar.vue';
import { initializeTheme } from './composables/useAppearance';
import { setCurrentLocale, useI18n } from './composables/useI18n';
import { useLoadingBar } from './composables/useLoadingBar';
import { unreadConversationsCount } from './composables/useNotifications';
import { formatNumber } from './i18n';

const { t, locale } = useI18n();

createInertiaApp({
    title: (title) => {
        // The name follows the interface language too, so an Arabic tab never ends in "Social CRM".
        const appName = t('app.name');
        const base = title ? `${title} - ${appName}` : appName;

        const unread = unreadConversationsCount();

        return unread > 0 ? `(${formatNumber(locale.value, unread)}) ${base}` : base;
    },
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // <html lang dir> follows the shared `locale` prop on load and after every visit.
        setCurrentLocale(props.initialPage.props.locale as string | undefined);
        router.on('navigate', (event) => setCurrentLocale(event.detail.page.props.locale as string | undefined));

        // TopLoadingBar is mounted once beside the page root, so guest pages (login) and
        // every authenticated layout share the same bar without mounting it per layout.
        createApp({ render: () => [h(App, props), h(TopLoadingBar)] })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    // The global TopLoadingBar replaces Inertia's own progress bar, so there is only one.
    progress: false,
});

// Page visits drive the same bar as API requests. `finish` fires for completed,
// cancelled and interrupted visits alike, so every `start` is always balanced.
const loadingBar = useLoadingBar();
router.on('start', () => loadingBar.start());
router.on('finish', () => loadingBar.done());

// This will set light / dark mode on page load...
initializeTheme();
