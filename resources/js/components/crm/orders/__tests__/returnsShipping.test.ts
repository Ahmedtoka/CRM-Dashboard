import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.hoisted(() => vi.fn());
vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ post }),
    apiErrorMessage: (e: { response?: { data?: { message?: string } } }, fallback: string) => e.response?.data?.message ?? fallback,
}));
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push: vi.fn() }) }));

import ReturnLifecycle from '@/components/crm/cases/ReturnLifecycle.vue';
import ShippingTimeline from '@/components/crm/orders/ShippingTimeline.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import type { ReturnView, ShippingSummary } from '@/types/returnsShipping';

beforeAll(() => setCurrentLocale('ar'));
beforeEach(() => {
    post.mockReset();
});

const view = (over: Partial<ReturnView> = {}): ReturnView => ({
    stage: 'requested',
    stage_at: '2026-10-24T10:00:00+03:00',
    is_exchange: false,
    pickup_date: null,
    refund_amount: null,
    exchange_order: null,
    next: ['pickup_scheduled', 'cancelled'],
    timeline: [{ stage: 'requested', at: '2026-10-24T10:00:00+03:00', by: null, meta: {}, notified: null, notify_error: null }],
    ...over,
});

describe('ReturnLifecycle', () => {
    it('offers only the next steps and asks for the pickup day before sending it', async () => {
        post.mockResolvedValue({ data: { data: { id: 5 } } });
        const w = mount(ReturnLifecycle, { props: { caseId: 5, view: view() } });

        expect(w.findAll('[data-return-action]').map((b) => b.attributes('data-return-action'))).toEqual(['pickup_scheduled', 'cancelled']);
        expect(w.find('[data-return-stage]').text()).toBe('الطلب مسجّل');

        await w.find('[data-return-action="pickup_scheduled"]').trigger('click');
        await w.find('[data-return-field="pickup_date"]').setValue('2026-10-26');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/cases/5/return/pickup_scheduled', { pickup_date: '2026-10-26' });
        expect(w.emitted('updated')).toHaveLength(1);
    });

    it('asks for the cash amount at pickup for a return but not for an exchange', async () => {
        const pickedUp = view({ stage: 'pickup_scheduled', next: ['pickup_scheduled', 'picked_up', 'cancelled'] });
        const ret = mount(ReturnLifecycle, { props: { caseId: 5, view: pickedUp } });
        await ret.find('[data-return-action="picked_up"]').trigger('click');
        expect(ret.find('[data-return-field="refund_amount"]').exists()).toBe(true);

        const exchange = mount(ReturnLifecycle, { props: { caseId: 5, view: { ...pickedUp, is_exchange: true } } });
        await exchange.find('[data-return-action="picked_up"]').trigger('click');
        expect(exchange.find('[data-return-field="refund_amount"]').exists()).toBe(false);
    });

    it("sends the steps that need no fact at once and shows the server's refusal", async () => {
        post.mockRejectedValue({ response: { data: { message: 'الخطوة دي مش دورها دلوقتي.' } } });
        const w = mount(ReturnLifecycle, { props: { caseId: 9, view: view({ stage: 'picked_up', next: ['received'] }) } });

        await w.find('[data-return-action="received"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/cases/9/return/received', {});
        expect(w.find('[data-return-error]').text()).toBe('الخطوة دي مش دورها دلوقتي.');
    });

    it('says whether the customer was told, and why not', () => {
        const w = mount(ReturnLifecycle, {
            props: {
                caseId: 5,
                view: view({
                    stage: 'pickup_scheduled',
                    timeline: [
                        { stage: 'requested', at: '2026-10-24T10:00:00+03:00', by: null, meta: {}, notified: null, notify_error: null },
                        {
                            stage: 'pickup_scheduled',
                            at: '2026-10-24T11:00:00+03:00',
                            by: 'منى',
                            meta: { pickup_date: '2026-10-26' },
                            notified: false,
                            notify_error: 'window_closed',
                        },
                    ],
                }),
            },
        });

        const note = w.find('[data-return-notified]').text();
        expect(note).toContain('العميلة ما اتبلغتش');
        expect(note).toContain('نافذة الرد مقفولة');
    });

    it('offers nothing once the case is closed', () => {
        const w = mount(ReturnLifecycle, { props: { caseId: 5, view: view({ stage: 'closed', next: [] }) } });
        expect(w.find('[data-return-actions]').exists()).toBe(false);
    });
});

describe('ShippingTimeline', () => {
    const summary = (over: Partial<ShippingSummary> = {}): ShippingSummary => ({
        step: 'created',
        step_at: '2026-10-24T09:00:00+03:00',
        failed_attempt: false,
        delivered_at: null,
        carrier_updates: false,
        tracking: { company: 'Bosta', number: 'B1', url: 'https://bosta.co/t/B1' },
        timeline: [
            {
                step: 'created',
                status: null,
                at: '2026-10-24T09:00:00+03:00',
                fulfillment_id: 1,
                message: null,
                tracking_company: 'Bosta',
                tracking_number: 'B1',
                tracking_url: 'https://bosta.co/t/B1',
            },
        ],
        ...over,
    });

    it('says plainly when Shopify has no carrier update yet', () => {
        const w = mount(ShippingTimeline, { props: { shipping: summary() } });
        expect(w.find('[data-shipping-step]').text()).toBe('تم الإنشاء');
        expect(w.find('[data-shipping-no-updates]').exists()).toBe(true);
    });

    it('marks a failed delivery and links the carrier', () => {
        const w = mount(ShippingTimeline, {
            props: {
                shipping: summary({
                    step: 'failed_attempt',
                    failed_attempt: true,
                    carrier_updates: true,
                    timeline: [
                        ...summary().timeline,
                        {
                            step: 'failed_attempt',
                            status: 'failure',
                            at: '2026-10-25T12:00:00+03:00',
                            fulfillment_id: 1,
                            message: 'Customer not reachable',
                            tracking_company: 'Bosta',
                            tracking_number: 'B1',
                            tracking_url: null,
                        },
                    ],
                }),
            },
        });
        expect(w.find('[data-shipping-failed]').exists()).toBe(true);
        expect(w.find('[data-shipping-no-updates]').exists()).toBe(false);
        expect(w.text()).toContain('Customer not reachable');
        expect(w.find('a').attributes('href')).toBe('https://bosta.co/t/B1');
    });

    it('is the one-line version on the thread card', () => {
        const w = mount(ShippingTimeline, { props: { shipping: summary(), compact: true } });
        expect(w.find('ol').exists()).toBe(false);
        expect(w.find('[data-shipping-step]').exists()).toBe(true);
    });
});
