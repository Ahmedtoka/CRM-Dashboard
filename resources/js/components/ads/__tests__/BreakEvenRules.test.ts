import BreakEvenRules from '@/components/ads/BreakEvenRules.vue';
import type { RulesSetupProps } from '@/types/ads';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const put = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { put: (...a: unknown[]) => put(...a), post: vi.fn() } }));

const base: RulesSetupProps = {
    global: { margin_pct: null, shipping_subsidy: null, return_cost: null, target_cpp: null, target_cpo: null },
    general: { low_stock_units: 10, spike_min_amount: 1000 },
    notify_enabled: false,
    can_edit: true,
    default_floor: 2.5,
    accounts: [
        {
            id: 7,
            name: 'LV-Main 2',
            currency: 'EGP',
            platform: 'meta',
            inputs: { margin_pct: null, shipping_subsidy: null, return_cost: null, target_cpp: null, target_cpo: null },
            effective: { floor: 2.5, is_default: true, unprofitable: false, margin_pct: null, shipping_subsidy: 0, return_cost: 0, aov: 1200, aov_source: 'account', refusal_rate: 0.2, refusal_source: 'account', max_cpa: null, tax_rate: 0.14 },
            targets: { cpp: null, cpp_source: 'none', cpo: 300, cpo_source: 'median', cpc: 20 },
        },
    ],
};

describe('BreakEvenRules', () => {
    it('shows the default floor chip and saves the global numbers', async () => {
        const w = mount(BreakEvenRules, { props: base });
        expect(w.get('[data-test="floor"]').text()).toBe('2.50');
        expect(w.find('[data-test="floor-default"]').exists()).toBe(true);

        await w.get('[data-test="global-margin_pct"]').setValue('55');
        await w.get('[data-test="save-global"]').trigger('click');

        expect(put).toHaveBeenCalledWith('/ads/setup/rules', expect.objectContaining({ account_id: null, margin_pct: 55, target_cpp: null }), expect.anything());
    });

    it('is read-only without Ads authority', () => {
        const w = mount(BreakEvenRules, { props: { ...base, can_edit: false } });
        expect(w.find('[data-test="read-only"]').exists()).toBe(true);
        expect(w.find('[data-test="save-global"]').exists()).toBe(false);
        expect(w.find('[data-test="notify-toggle"]').exists()).toBe(false);
    });
});
