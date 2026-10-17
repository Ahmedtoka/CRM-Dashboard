import SectionTabs from '@/components/crm/SectionTabs.vue';
import type { SectionTab } from '@/lib/sectionTabs';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const page = { url: '/orders', props: { locale: 'ar' } };
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const ORDERS: SectionTab[] = [
    { label: 'كل الأوردرات', href: '/orders' },
    { label: 'التأكيدات', href: '/orders/confirmations', badge: 4 },
    { label: 'التحليل', href: '/orders?tab=analytics' },
    { label: 'من الإعلانات', href: '/orders?tab=ads' },
];

function render(url: string, tabs: SectionTab[] = ORDERS) {
    page.url = url;

    return mount(SectionTabs, { props: { tabs, label: 'الأوردرات' } });
}

const current = (w: ReturnType<typeof render>) => w.findAll('[aria-current="page"]').map((a) => a.text());

describe('SectionTabs', () => {
    it('renders every tab as a link to its own URL', () => {
        const w = render('/orders');

        expect(w.findAll('a').map((a) => a.attributes('href'))).toEqual(['/orders', '/orders/confirmations', '/orders?tab=analytics', '/orders?tab=ads']);
        expect(w.find('nav').attributes('aria-label')).toBe('الأوردرات');
    });

    it('marks the tab whose path and query fit the page best', () => {
        expect(current(render('/orders'))).toEqual(['كل الأوردرات']);
        expect(current(render('/orders?status=paid&page=2'))).toEqual(['كل الأوردرات']);
        expect(current(render('/orders?tab=analytics&from=2026-10-01'))[0]).toContain('التحليل');
        expect(current(render('/orders/confirmations?tab=needs_call'))[0]).toContain('التأكيدات');
    });

    it('lets a tab claim other paths', () => {
        const tabs = [
            { label: 'الأداء', href: '/reports/team', match: ['/reports/users'] },
            { label: 'الطابور', href: '/reports/queue' },
        ];

        expect(current(render('/reports/users/7', tabs))).toEqual(['الأداء']);
    });

    it('shows a badge, red when urgent', () => {
        const w = render('/orders', [ORDERS[0], { ...ORDERS[1], urgent: true }]);
        const badge = w.find('[data-nav-badge]');

        expect(badge.text()).toMatch(/^٤/);
        expect(badge.attributes('data-urgent')).toBe('true');
    });

    it('hides itself when the person may see one tab only', () => {
        expect(render('/orders', [ORDERS[0]]).find('nav').exists()).toBe(false);
    });
});
