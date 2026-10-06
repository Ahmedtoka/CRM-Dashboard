import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    router: { put: vi.fn(), post: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    useForm: (data: Record<string, unknown>) => ({ ...data, errors: {}, processing: false, put: vi.fn() }),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import SetupRules from '@/pages/Ads/SetupRules.vue';

const settings = { tax_rate: 0.14, tax_rate_percent: 14, winner_thresholds: { winner: 3, promising: 2, loser: 1, loser_min_spend: 500, min_spend: 300, min_days: 3 } };
const rules = {
    global: { margin_pct: null, shipping_subsidy: null, return_cost: null, target_cpp: null, target_cpo: null },
    general: { low_stock_units: 10, spike_min_amount: 1000 },
    notify_enabled: false,
    can_edit: true,
    default_floor: 2.5,
    accounts: [],
};
const stubs = { AppLayout: { template: '<div><slot /></div>' }, PageHeader: true, AdsSetupTabs: true };

describe('SetupRules', () => {
    it('renders the break-even rules from the rules prop', () => {
        const w = mount(SetupRules, { props: { settings, launchExpiryDays: 7, rules } as never, global: { stubs } });
        expect(w.find('[data-test="breakeven-rules"]').exists()).toBe(true);
        expect(w.find('[data-test="notify-toggle"]').exists()).toBe(true);
    });

    it('leaves the section out without the prop', () => {
        const w = mount(SetupRules, { props: { settings, launchExpiryDays: 7 } as never, global: { stubs } });
        expect(w.find('[data-test="breakeven-rules"]').exists()).toBe(false);
    });
});
