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
        decisions: { total: 2, approvals: 2, suggestions: [], suggestions_total: 0, alerts: [], alerts_total: 0 },
        money_today: { spend_so_far: 4120, usual_by_now: 3900, ratio: 1.06, baseline: 'snapshots', hour: 15, hours: [], conversations: 146, orders: 38 },
        last7: { from: '2026-09-29', to: '2026-10-05', totals, daily: [] },
        buyers: null, best: [], worst: [],
    },
};
const stubs = { AppLayout: { template: '<div><slot /></div>' }, AdsFilterBar: true, AdDrawer: true, ComboChart: true, DataHealthBanner: true, DigestCard: { template: '<div data-test="digest" />' }, PageHeader: { props: ['title'], template: '<h1>{{ title }}</h1>' } };

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
        const today = { ...base.today, decisions: { ...base.today.decisions, total: 3, suggestions: [s], suggestions_total: 1 }, best: [row] };
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
    it('opens with the morning digest card', () => {
        const w = mount(Today, { props: base as never, global: { stubs } });
        expect(w.find('[data-test="digest"]').exists()).toBe(true);
    });

    it('counts the one open-decisions total and lists the top alert cards (final fixes 3 and 8)', () => {
        const reason = { alert_id: 1, rule_id: 'sales.no_orders', severity: 'high', action: 'stop', sentence_key: 'x.y', params: {}, evidence: {}, first_fired_at: null, seen: false, state: 'open', snoozed_until: null, closed_at: null, closed_by: null, resolved_reason: null, dismiss_reason: null, can_dismiss: true };
        const card = { key: 'ad:4', kind: 'ad', severity: 'high', money_at_risk_per_day: 320, first_fired_at: null, ad: { id: 4, external_id: '4', name: 'Abaya reel', thumbnail_url: null, account_id: 1, account: 'LV', buyer: null, can_write: true }, product: null, account: null, ads: [], reasons: [reason], primary: { verb: 'stop', action: 'stop', alert_id: 1, href: null }, alert_ids: [1] };
        const decisions = { total: 9, approvals: 0, suggestions: [], suggestions_total: 0, alerts: [card], alerts_total: 6 };
        const w = mount(Today, { props: { ...base, today: { ...base.today, decisions } } as never, global: { stubs } });
        expect(w.find('#today-decisions').text()).toContain('9');
        expect(w.find('[data-alert-card="ad:4"]').text()).toContain('Abaya reel');
        expect(w.find('[data-test="more-alerts"]').text()).toContain('5');
        expect(w.text()).not.toContain('Nothing needs a decision');
    });
});
