import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn() },
    usePage: () => ({ props: { ads: { isBuyer: false } }, url: '/ads/numbers' }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import Numbers from '@/pages/Ads/Numbers.vue';

const totals = {
    spend: 1000, spend_tax: 1140, purchase_value: 2000, roas: 2, purchases: 4, cpa: 250, impressions: 9000, clicks: 100, ctr: 0.011, reach: 5000, cpm: 111, cpc: 10,
    real_orders: 12, real_revenue: 2400, real_roas: 2.4, losers_spend_share: 0.09, conversations: 50, conversations_ordered: 10, mixed_currencies: false, spend_outside_active: 0, source: 'account',
};
const base = {
    filters: { from: '2026-10-01', to: '2026-10-06', range: 'this_month', platform: null, buyer: null, accounts: [], section: null },
    buyers: [], platforms: ['meta'], currency: 'EGP', data_health: { reasons: [] }, numbers_under_review: false, clamped_to_history: false, account_options: [], freshness: null, sync_errors: [],
    sync: { last_synced_at: null, oldest: null, errors: [] },
    overview: { totals, daily: [], platforms: [], currency: 'EGP', tax_rate: 0.14 }, summary: null,
    top_accounts: [{ id: 3, name: 'LV-Main', external_id: 'act_1', platform: 'meta', currency: 'EGP', buyer: null, spend: 1000, spend_tax: 1140, purchase_value: 2000, purchases: 4, roas: 2, status: 'ACTIVE', last_synced_at: null }],
    chat_campaigns: { rows: [{ campaign: 'Eid', ads: [], conversations: 5, customers: 5, orders: 1, revenue: 300, spend: 100, cost_per_conversation: 20, cost_per_order: 100, roas: 3 }], totals: {}, currency: null, spend_available: true },
};
const stubs = { AppLayout: { template: '<div><slot /></div>' }, AdsFilterBar: true, ComboChart: true, DataHealthBanner: true, RevenueSummaryCard: true, BuyerCard: true, PageHeader: true };

describe('Numbers', () => {
    it('shows five hero tiles and hides the rest behind a disclosure', () => {
        const w = mount(Numbers, { props: base as never, global: { stubs } });
        expect(w.findAll('[data-test="hero"]')).toHaveLength(5);
        expect(w.find('details[data-test="more"]').exists()).toBe(true);
    });

    it('turns accounts and the losers share into links (no dead ends)', () => {
        const w = mount(Numbers, { props: base as never, global: { stubs } });
        expect(w.find('[data-test="account-link-3"]').attributes('href')).toContain('/ads/explorer?');
        expect(w.find('[data-test="account-link-3"]').attributes('href')).toContain('accounts=3');
        expect(w.find('[data-test="losers-link"]').attributes('href')).toContain('health=losing');
    });

    it('shows the chat campaigns table from the old /reports/ads page', () => {
        expect(mount(Numbers, { props: base as never, global: { stubs } }).find('#chat').text()).toContain('Eid');
    });

    it('gives the filter bar buyer options from the buyer cards', () => {
        const card = { buyer_id: 5, name: 'Bakinam' };
        const w = mount(Numbers, { props: { ...base, buyers: [card, { buyer_id: null, name: 'Unassigned' }] } as never, global: { stubs } });
        expect(w.findComponent({ name: 'AdsFilterBar' }).props('buyers')).toEqual([{ id: 5, name: 'Bakinam' }]);
    });
});
