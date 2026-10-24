import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: { get: (...args: unknown[]) => get(...args) },
    usePage: () => ({ props: { auth: { user: { id: 1, role: 'supervisor' } } }, url: '/reports/outcomes?group=week' }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import Outcomes from '@/pages/Reports/Outcomes.vue';

const figures = (over: Record<string, unknown> = {}) => ({
    total: 10,
    counted: 8,
    by_outcome: {},
    purchases: 1,
    lost: 2,
    returns: 1,
    complaints: 2,
    complaints_pending: 1,
    no_reply: 2,
    no_reply_pct: 25,
    purchase_pct: 13,
    avg_close_seconds: 300,
    suggested: 3,
    followed: 2,
    followed_pct: 67,
    automatic: 2,
    unknown: 1,
    ...over,
});
const byOutcome = { ordered: 1, price: 1, no_answer: 2, return_case: 1, complaint_case: 2, order_inquiry: 1, spam: 1, unknown: 1 };
const report = {
    from: '2026-10-23',
    to: '2026-10-24',
    group: 'week',
    user_id: null,
    kpis: figures({ by_outcome: byOutcome }),
    by_outcome: byOutcome,
    periods: [{ key: '2026-10-17', from: '2026-10-17', ...figures() }],
    rows: [
        { user: { id: 3, name: 'زينب', color: '#5E2B6E' }, ...figures({ total: 5 }) },
        { user: null, ...figures({ total: 2 }) },
    ],
};
const props = {
    range: { from: '2026-10-23', to: '2026-10-24' },
    group: 'week',
    userId: null,
    moderators: [{ id: 3, name: 'زينب', color: null }],
    report,
};
const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    PeriodControls: true,
    SectionTabs: true,
    PageHeader: { template: '<div><slot /></div>' },
};

describe('Reports › Outcomes', () => {
    it('shows the totals, the split by outcome in the sheet order, and the exact counts per moderator and period', () => {
        const w = mount(Outcomes, { props: props as never, global: { stubs } });

        expect(w.find('[data-ledger="total"]').text()).toContain('١٠');
        expect(w.find('[data-ledger="no_reply"]').text()).toContain('٢٥%');
        expect(w.find('[data-ledger="complaints"]').text()).toContain('١ لسه مفتوحة');
        expect(w.find('[data-outcomes-followed]').text()).toContain('٦٧%');
        expect(w.findAll('[data-split]').map((r) => r.attributes('data-split'))).toEqual([
            'ordered',
            'price',
            'order_inquiry',
            'return_case',
            'complaint_case',
            'no_answer',
            'spam',
            'unknown',
        ]);
        expect(w.find('[data-split="spam"]').text()).not.toContain('%');
        expect(w.find('[data-table-id="report-outcomes-users"]').text()).toContain('زينب');
        expect(w.find('[data-table-id="report-outcomes-users"]').text()).toContain('تلقائي / البوت');
        expect(w.find('[data-table-id="report-outcomes-periods"]').text()).toContain('أسبوع 2026-10-17');
    });

    it('links the CSV with the current range, grouping and moderator, and re-queries when grouping changes', async () => {
        const w = mount(Outcomes, { props: { ...props, userId: 3 } as never, global: { stubs } });

        expect(w.find('[data-export]').attributes('href')).toBe('/reports/outcomes/export?from=2026-10-23&to=2026-10-24&group=week&user=3');
        await w.find('[data-outcomes-group]').setValue('month');
        expect(get).toHaveBeenCalledWith('/reports/outcomes', { from: '2026-10-23', to: '2026-10-24', group: 'month', user: 3 }, expect.anything());
    });

    it('says so when nothing closed in the range', () => {
        const empty = { ...report, kpis: figures({ total: 0, counted: 0 }), by_outcome: {}, periods: [], rows: [] };
        const w = mount(Outcomes, { props: { ...props, report: empty } as never, global: { stubs } });

        expect(w.text()).toContain('مفيش محادثات اتقفلت في الفترة دي');
        expect(w.find('[data-outcomes-split]').exists()).toBe(false);
    });
});
