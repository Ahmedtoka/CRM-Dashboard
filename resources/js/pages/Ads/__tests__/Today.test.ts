import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { ads: { canWrite: true, isBuyer: false, buyerId: null } }, url: '/ads' }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import { setCurrentLocale } from '@/composables/useI18n';
import Today from '@/pages/Ads/Today.vue';

beforeAll(() => setCurrentLocale('en'));

const totals = { spend: 31200, spend_tax: 35568, real_orders: 212, real_revenue: 74880, real_roas: 2.4, roas: 3.1, losers_spend_share: 0.09 };
const base = {
    filters: { from: '2026-09-29', to: '2026-10-05', range: 'last7', platform: null, buyer: null, accounts: [] },
    buyers: [], platforms: ['meta'], currency: 'EGP', data_health: { reasons: [] }, numbers_under_review: false, clamped_to_history: false, account_options: [], freshness: null,
    today: {
        decisions: { approvals: 2, suggestions: [], suggestions_total: 0, alerts: [] },
        money_today: { spend_so_far: 4120, usual_by_now: 3900, ratio: 1.06, baseline: 'snapshots', hour: 15, hours: [], conversations: 146, orders: 38 },
        last7: { from: '2026-09-29', to: '2026-10-05', totals, daily: [] },
        buyers: null, best: [], worst: [],
    },
};
const stubs = { AppLayout: { template: '<div><slot /></div>' }, AdsFilterBar: true, AdDrawer: true, ComboChart: true, DataHealthBanner: true, PageHeader: { props: ['title'], template: '<h1>{{ title }}</h1>' } };

describe('Today', () => {
    it('leads with decisions, then money today and the last 7 complete days with real ROAS first', () => {
        const w = mount(Today, { props: base as never, global: { stubs } });
        const text = w.text();
        expect(text.indexOf('Needs your decision')).toBeLessThan(text.indexOf('Money today'));
        expect(w.find('[data-test="approvals-link"]').attributes('href')).toBe('/ads/approvals');
        expect(w.find('[data-test="real-roas"]').text()).toContain('2.4');
        expect(w.find('[data-test="losers-link"]').attributes('href')).toContain('health=losing');
        expect(w.find('[data-test="buyers-strip"]').exists()).toBe(false);
    });

    it('passes today spend to Stop, keeps real ROAS on phones and gives «ليه؟» a 44 px target', () => {
        const s = { ad_id: 7, external_id: '1', account_id: 1, account: 'LV', platform: 'meta', name: 'Eid', spend: 1500, spend_tax: 1710, roas: null, reasons: [], can_write: true, objective: 'sales', thumbnail_url: null, campaign: null, status: 'ACTIVE', spend_today: 95 };
        const row = { id: 9, external_id: '9', name: 'Best', platform: 'meta', account: 'LV', account_id: 1, status: 'ACTIVE', effective_status: 'ACTIVE', objective: 'sales', health: [], series: [], real_roas: 3, roas: 2, currency: 'EGP', spend_tax: 10, spend_today: 1, conversations: 0, real_orders: 0, purchases: 0, clicks: 0, can_write: true, parent_paused: false, buyer: null };
        const today = { ...base.today, decisions: { ...base.today.decisions, suggestions: [s], suggestions_total: 1 }, best: [row] };
        const w = mount(Today, { props: { ...base, today } as never, global: { stubs: { ...stubs, AdStatusButton: { name: 'AdStatusButton', props: ['spendToday'], template: '<i />' }, CreativeThumb: true, AdRow: { props: ['part'], template: '<div :data-part="part" />' } } } });
        expect(w.findComponent({ name: 'AdStatusButton' }).props('spendToday')).toBe(95);
        const ret = w.find('[data-test="best-worst-return"]');
        expect(ret.exists()).toBe(true);
        expect(ret.classes()).not.toContain('hidden');
        expect(w.find('[data-test="why"]').classes()).toContain('h-11');
    });

    it('shows the buyers strip for managers with a link to their open decisions', () => {
        const buyers = [{ buyer_id: 5, name: 'Bakinam', color: null, accounts: [], spend: 100, spend_tax: 114, purchase_value: 0, roas: null, purchases: 0, cpa: null, ctr: null, real_orders: 0, real_revenue: 0, real_roas: 2, conversations: 0, conversations_ordered: 0, budget: 1000, budget_used_pct: 40, target_roas: 3, roas_vs_target: null, open_decisions: 2 }];
        const w = mount(Today, { props: { ...base, today: { ...base.today, buyers } } as never, global: { stubs } });
        expect(w.find('[data-test="buyers-strip"]').exists()).toBe(true);
        expect(w.find('a[href="/ads/decisions?buyer=5"]').exists()).toBe(true);
    });
});
