import BreakEvenRules from '@/components/ads/BreakEvenRules.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { RulesSetupProps } from '@/types/ads';
import { mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const put = vi.fn();
const pageProps: { errors: Record<string, string> } = { errors: {} };
const push = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { put: (...a: unknown[]) => put(...a), post: vi.fn() }, usePage: () => ({ props: pageProps }) }));
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push }) }));

beforeAll(() => setCurrentLocale('en'));
beforeEach(() => {
    put.mockReset();
    push.mockReset();
    pageProps.errors = {};
});

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

    it('reads Arabic digits and separators, then toasts on success (final fix 5)', async () => {
        put.mockImplementation((_u: string, _d: unknown, o: { onSuccess?: () => void }) => o.onSuccess?.());
        const w = mount(BreakEvenRules, { props: base });
        await w.get('[data-test="global-margin_pct"]').setValue('٥٥٫٥');
        await w.get('[data-test="global-shipping_subsidy"]').setValue('١٬٢٠٠');
        await w.get('[data-test="global-return_cost"]').setValue('2,5');
        await w.get('[data-test="save-global"]').trigger('click');

        expect(put).toHaveBeenCalledWith('/ads/setup/rules', expect.objectContaining({ margin_pct: 55.5, shipping_subsidy: 1200, return_cost: 2.5 }), expect.anything());
        expect(push).toHaveBeenCalledWith('Saved');
    });

    it('blocks a margin above 100 and a negative cost with messages under the inputs (final fix 5)', async () => {
        const w = mount(BreakEvenRules, { props: base });
        await w.get('[data-test="global-margin_pct"]').setValue('150');
        await w.get('[data-test="global-return_cost"]').setValue('-3');
        await w.get('[data-test="save-global"]').trigger('click');

        expect(put).not.toHaveBeenCalled();
        expect(w.get('[data-test="error-global-margin_pct"]').text()).toContain('100');
        expect(w.get('[data-test="error-global-return_cost"]').text()).toBe('Cannot be negative');
    });

    it('shows the server errors under the form that was saved (final fix 5)', async () => {
        pageProps.errors = { margin_pct: 'The margin is invalid.' };
        const w = mount(BreakEvenRules, { props: base });
        expect(w.find('[data-test="error-global-margin_pct"]').exists()).toBe(false); // nothing saved from this form yet
        await w.get('[data-test="global-margin_pct"]').setValue('40');
        await w.get('[data-test="save-global"]').trigger('click');

        expect(w.get('[data-test="error-global-margin_pct"]').text()).toBe('The margin is invalid.');
    });

    it('shows the full D12 sentence next to a default break-even (final fix 5)', () => {
        const w = mount(BreakEvenRules, { props: base });
        expect(w.get('[data-test="floor-default-sentence"]').text()).toContain('2.5');
    });
});
