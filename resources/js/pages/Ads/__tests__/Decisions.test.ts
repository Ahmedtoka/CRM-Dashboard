import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { ads: { canWrite: true } }, url: '/ads/decisions' }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import { setCurrentLocale } from '@/composables/useI18n';
import Decisions from '@/pages/Ads/Decisions.vue';

beforeAll(() => setCurrentLocale('en'));

const base = {
    filters: { from: '2026-09-29', to: '2026-10-05', range: 'last7', platform: null, buyer: null, accounts: [], tab: 'open', who: null, level: null, result: null },
    buyers: [], platforms: ['meta'], currency: 'EGP', data_health: { reasons: [] }, numbers_under_review: false, clamped_to_history: false, account_options: [], freshness: null,
    counts: { open: 4, snoozed: 0, closed: 0 }, approvals: { count: 3, href: '/ads/approvals', items: [] }, alerts: [], log: [], log_users: [],
    suggestions: [
        { ad_id: 7, external_id: '1', account_id: 1, account: 'LV', platform: 'meta', name: 'Eid Abaya', spend: 1500, spend_tax: 1710, roas: null, reasons: [{ key: 'no_purchases', params: { spend: 1500, days: 14 } }], can_write: true, objective: 'sales', thumbnail_url: null, campaign: 'Eid', status: 'ACTIVE' },
    ],
};
const stubs = { AppLayout: { template: '<div><slot /></div>' }, AdsFilterBar: true, AdDrawer: true, AdStatusButton: true, DataHealthBanner: true, PageHeader: true, CreativeThumb: true };

describe('Decisions', () => {
    it('puts launch approvals above stop suggestions and shows tab counts', () => {
        const w = mount(Decisions, { props: base as never, global: { stubs } });
        const html = w.html();
        expect(html.indexOf('data-test="approvals"')).toBeLessThan(html.indexOf('data-test="suggestions"'));
        expect(w.find('[data-test="tab-open"]').text()).toContain('4');
        expect(w.text()).toContain('Eid Abaya');
    });

    it('shows the log table on the log tab', () => {
        const log = [{ id: 1, ad_id: 7, at: '2026-10-06T08:00:00Z', user: 'Bakinam', user_id: 2, platform: 'meta', account: 'LV', account_id: 1, level: 'ad', name: 'Eid', external_id: '1', from_status: 'ACTIVE', to_status: 'PAUSED', reason: null, result: 'ok', error: null, source: 'ui' }];
        const w = mount(Decisions, {
            props: { ...base, filters: { ...base.filters, tab: 'log' }, suggestions: [], log } as never,
            global: { stubs: { ...stubs, DataTable: { props: ['rows', 'caption'], template: '<table><tr v-for="r in rows" :key="r.id"><td>{{ r.user }}</td></tr></table>' } } },
        });
        expect(w.text()).toContain('Bakinam');
        expect(w.find('[data-test="suggestions"]').exists()).toBe(false);
    });

    it('says nothing is open when there are no approvals and no suggestions', () => {
        const w = mount(Decisions, { props: { ...base, approvals: null, suggestions: [], counts: { open: 0, snoozed: 0, closed: 0 } } as never, global: { stubs } });
        expect(w.text()).toContain('No open decisions right now');
    });
    it('renders the alert cards between the approvals and the suggestions, and the snoozed cards on their tab', () => {
        const alertCard = {
            key: 'ad:9', kind: 'ad', severity: 'high', money_at_risk_per_day: 200, first_fired_at: null,
            ad: { id: 9, external_id: '9', name: 'Alert Ad', thumbnail_url: null, account_id: 1, account: 'LV', buyer: null, can_write: true },
            product: null, account: null, ads: [],
            reasons: [{ alert_id: 3, rule_id: 'all.spend_no_result', severity: 'high', action: 'stop', sentence_key: 'spend_no_result', params: { spend: 900, k: 3, days: 4, result: 'purchase', cap: 1500 }, evidence: {}, first_fired_at: null, seen: true, state: 'open', snoozed_until: null, closed_at: null, closed_by: null, resolved_reason: null, dismiss_reason: null, can_dismiss: true }],
            primary: { verb: 'stop', action: 'stop', alert_id: 3, href: null }, alert_ids: [3],
        };
        const alertsMeta = { tab: 'open', counts: { open: 1, later: 0, closed: 0 }, hidden_by_cap: 0, buyer_cap: 7, shadow: false, can_toggle: false, can_open_settings: false };
        const w = mount(Decisions, { props: { ...base, alerts: [alertCard], alertsMeta } as never, global: { stubs: { ...stubs, WriteActionDialog: true } } });
        const html = w.html();
        expect(html.indexOf('data-test="approvals"')).toBeLessThan(html.indexOf('data-test="alerts"'));
        expect(html.indexOf('data-test="alerts"')).toBeLessThan(html.indexOf('data-test="suggestions"'));
        expect(w.text()).toContain('Alert Ad');

        const later = mount(Decisions, {
            props: { ...base, filters: { ...base.filters, tab: 'snoozed' }, suggestions: [], alerts: [], alertsMeta: { ...alertsMeta, tab: 'later' } } as never,
            global: { stubs: { ...stubs, WriteActionDialog: true } },
        });
        expect(later.text()).toContain('Nothing snoozed');
    });
});
