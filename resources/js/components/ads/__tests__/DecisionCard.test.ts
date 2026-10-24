import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() }, usePage: () => ({ props: { ads: { canWrite: true } } }) }));

import DecisionCard from '@/components/ads/DecisionCard.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { DailyDecision } from '@/types/ads';

beforeAll(() => setCurrentLocale('en'));

const base: DailyDecision = {
    key: 'ad:1',
    kind: 'scale',
    action: { type: 'set_budget', pct: 20 },
    ad: { id: 1, external_id: '9300', name: 'Noor reel', account_id: 1, account: 'LV Meta', platform: 'meta', status: 'ACTIVE', effective_status: 'ACTIVE', thumbnail_url: null, campaign: 'SALES', objective: 'sales', currency: 'EGP', parent_paused: false },
    evidence: { spend: 3420, spend_pre_tax: 3000, results: 3, real_orders: 2, conversations: 0, revenue: 12000, real_revenue: 2000, roas: 4, real_roas: 0.67, cac: 1710, trend: { roas_pct: null, spend_pct: null, dir: 'flat' }, fatigue: { flag: false, ctr_drop_pct: null, frequency: null }, days: 3, tier: 'winner', score: 90 },
    reasons: [{ key: 'scale_winner', params: { pct: 20, roas: 4 } }],
    spend_today: 100,
    stock: null,
    can_write: true,
};
const stubs = { WriteActionDialog: true, CreativeThumb: true, PlatformChip: true };

describe('DecisionCard', () => {
    it('shows the kind, the evidence line and the execute button with the exact change', () => {
        const w = mount(DecisionCard, { props: { decision: base, currency: 'EGP', execute: true }, global: { stubs } });
        expect(w.text()).toContain('Scale');
        expect(w.text()).toContain('3 days');
        expect(w.text()).toContain('ROAS 4.00');
        const btn = w.find('[data-test="execute"]');
        expect(btn.exists()).toBe(true);
        expect(btn.text()).toContain('+20%');
    });

    it('a cut executes a pause, and says it is a dry run when execution is off', () => {
        const cut: DailyDecision = { ...base, kind: 'cut', action: { type: 'set_status', to: 'paused' }, reasons: [{ key: 'roas_below', params: { roas: 0.2, threshold: 0.8 } }] };
        const w = mount(DecisionCard, { props: { decision: cut, currency: 'EGP', execute: false }, global: { stubs } });
        expect(w.find('[data-test="execute"]').text()).toContain('Stop');
        expect(w.text()).toContain('Dry run');
    });

    it('a keep has no execute button, and no button either without write access', () => {
        const keep: DailyDecision = { ...base, kind: 'keep', action: null };
        expect(mount(DecisionCard, { props: { decision: keep, currency: 'EGP', execute: true }, global: { stubs } }).find('[data-test="execute"]').exists()).toBe(false);
        expect(mount(DecisionCard, { props: { decision: { ...base, can_write: false }, currency: 'EGP', execute: true }, global: { stubs } }).find('[data-test="execute"]').exists()).toBe(false);
    });

    it('a stock risk names the product and the units left', () => {
        const risk: DailyDecision = { ...base, kind: 'stock_risk', action: { type: 'set_status', to: 'paused' }, stock: { state: 'low', inventory: 2, threshold: 10, product: 'Abaya Noor', material: 'Noor' } };
        const w = mount(DecisionCard, { props: { decision: risk, currency: 'EGP', execute: true }, global: { stubs } });
        expect(w.text()).toContain('Abaya Noor');
        expect(w.text()).toContain('2');
    });
});

describe('DecisionCard Google', () => {
    it('has no execute button for Google (read-only) and says where to do it', () => {
        const g: DailyDecision = { ...base, ad: { ...base.ad, platform: 'google' } };
        const w = mount(DecisionCard, { props: { decision: g, currency: 'EGP', execute: true }, global: { stubs } });
        expect(w.find('[data-test="execute"]').exists()).toBe(false);
        expect(w.find('[data-test="google-note"]').exists()).toBe(true);
    });
});
