import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ get }), apiErrorMessage: (_e: unknown, f: string) => f }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, router: { post: vi.fn() } }));

import DigestCard from '@/components/ads/DigestCard.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { DigestData } from '@/types/ads';

beforeAll(() => setCurrentLocale('ar'));
beforeEach(() => get.mockReset());

const base: DigestData = {
    variant: 'owner',
    date: '2026-10-06',
    shadow: true,
    currency: 'EGP',
    yesterday: {
        spend: 1000,
        spend_tax: 1140,
        orders: 2,
        revenue: 1800,
        roas: 1.8,
        meta_roas: 1.2,
        usual_spend: 900,
        floor: 2.5,
        floor_default: true,
    },
    open: { count: 3, critical: 0, high: 3, money: 1000 },
    top: [
        {
            alert_id: 1,
            rule_id: 'all.spend_no_result',
            severity: 'high',
            sentence_key: 'spend_no_result',
            params: { spend: 900, k: 3, days: 4, result: 'purchase', cap: 1500 },
            ad: 'Eid Abaya',
            account: 'LV',
            buyer: 'Bakinam',
            money: 900,
            age_days: 3,
        },
    ],
    winners: [],
    stock: { ads: 0, products: [] },
    inbox: null,
    stale_accounts: 0,
    approvals: 2,
    review_waiting: 0,
    buyers: [{ buyer_id: 1, name: 'Bakinam', open: 2, acted: 1, dismissed: 1, dismissed_wrong_numbers: 1, silent_spend: 900 }],
    stopped_yesterday: 1,
};
const stubs = { SkeletonList: { template: '<div data-test="skeleton" />' } };

describe('DigestCard', () => {
    it('shows the owner digest with the buyers table and the wrong-numbers signal', async () => {
        get.mockResolvedValueOnce({ data: base });
        const w = mount(DigestCard, { global: { stubs } });
        expect(w.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();
        expect(get).toHaveBeenCalledWith('/ads/alerts/digest', { silent: true });
        expect(w.find('[data-test="buyers"]').exists()).toBe(true);
        expect(w.find('[data-test="wrong-numbers-1"]').classes()).toContain('font-bold');
        expect(w.text()).toContain('ومفيش ولا عملية شراء');
        expect(w.find('[data-test="shadow-chip"]').exists()).toBe(true);
        expect(w.find('[data-test="floor-default"]').exists()).toBe(true);
        expect(w.find('[data-test="roas"]').classes()).toContain('text-destructive');
        expect(w.find('a[href="/ads/approvals"]').exists()).toBe(true);
    });

    it('shows the buyer variant without the buyers table', async () => {
        get.mockResolvedValueOnce({ data: { ...base, variant: 'buyer', buyers: [], approvals: 0 } });
        const w = mount(DigestCard, { global: { stubs } });
        await flushPromises();
        expect(w.find('[data-test="buyers"]').exists()).toBe(false);
        expect(w.text()).toContain('قراراتك النهارده');
    });

    it('uses a digest handed in by the page without fetching', () => {
        const w = mount(DigestCard, { props: { digest: base }, global: { stubs } });
        expect(get).not.toHaveBeenCalled();
        expect(w.findAll('[data-test="top-item"]')).toHaveLength(1);
    });

    it('offers a retry when loading fails', async () => {
        get.mockRejectedValueOnce(new Error('boom'));
        const w = mount(DigestCard, { global: { stubs } });
        await flushPromises();
        expect(w.text()).toContain('الملخص ماتحمّلش');
        get.mockResolvedValueOnce({ data: base });
        await w.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-test="buyers"]').exists()).toBe(true);
    });
});
