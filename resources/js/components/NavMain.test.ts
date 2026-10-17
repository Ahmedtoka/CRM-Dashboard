import NavMain from '@/components/NavMain.vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import { translate } from '@/i18n';
import { buildNav, type NavContext } from '@/lib/navMenu';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const page = { url: '/inbox', props: { locale: 'ar' } };
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const t = (key: string, params?: Record<string, string | number>) => translate('ar', key, params);
const ctx = (over: Partial<NavContext> = {}): NavContext => ({ role: 'admin', canSeeBoard: true, devTools: false, onboarding: null, badges: {}, adsQuery: '', t, ...over });

async function render(items = buildNav(ctx()), url = '/inbox') {
    page.url = url;
    const Host = defineComponent({ setup: () => () => h(SidebarProvider, null, () => h(NavMain, { items })) });

    const w = mount(Host);
    await flushPromises();

    return w;
}

describe('NavMain', () => {
    it('opens only the group of the current page and marks the page', async () => {
        const w = await render(undefined, '/orders/confirmations');
        const current = w.findAll('[aria-current="page"]');

        expect(current).toHaveLength(1);
        expect(current[0].attributes('href')).toBe('/orders');
        expect(w.text()).toContain('ما بعد البيع');
        expect(w.text()).not.toContain('كل الإعلانات');
    });

    it('shows the sum of a closed group, red when one child is late', async () => {
        const w = await render(buildNav(ctx({ badges: { confirmations: 4, cases: 2, cases_urgent: true } })), '/today');
        const badges = w.findAll('[data-nav-badge]');

        expect(badges).toHaveLength(1);
        expect(badges[0].text()).toBe('٦');
        expect(badges[0].attributes('data-urgent')).toBe('true');
    });

    it('shows each child badge once its group is open', async () => {
        const w = await render(buildNav(ctx({ badges: { confirmations: 4, cases: 2 } })), '/cases');

        expect(w.findAll('[data-nav-badge]').map((b) => b.text())).toEqual(['٤', '٢']);
    });

    it('renders a flat menu with a divider before settings', async () => {
        const w = await render(buildNav(ctx({ role: 'moderator', canSeeBoard: false })), '/inbox');

        expect(w.findAll('[role="separator"]')).toHaveLength(1);
        expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual([
            '/inbox',
            '/comments',
            '/orders/confirmations',
            '/orders',
            '/cases',
            '/reports/me',
            '/settings',
        ]);
    });

    it('shows the onboarding pill with its progress', async () => {
        const w = await render(buildNav(ctx({ onboarding: { done: 3, total: 5 } })), '/today');

        expect(w.find('[data-onboarding-pill]').text()).toContain(t('nav.onboarding_progress', { done: 3, total: 5 }));
    });
});
