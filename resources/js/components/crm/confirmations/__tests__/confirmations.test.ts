import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { post, push } = vi.hoisted(() => ({ post: vi.fn(), push: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: { reload: vi.fn(), visit: vi.fn(), get: vi.fn() },
    usePage: () => ({ props: {}, url: '/orders/confirmations' }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ post }), apiErrorMessage: (_e: unknown, f: string) => f }));
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push }) }));

import CallOutcomeActions from '@/components/crm/confirmations/CallOutcomeActions.vue';
import ConfirmationStatusChip from '@/components/crm/confirmations/ConfirmationStatusChip.vue';
import ConfirmationTimeline from '@/components/crm/confirmations/ConfirmationTimeline.vue';
import { setCurrentLocale, useI18n } from '@/composables/useI18n';
import ar from '@/i18n/ar';
import en from '@/i18n/en';
import { CONFIRMATION_TONE, confirmationsRows, skipReasonLabel } from '@/lib/orderConfirmations';
import { CONFIRMATION_STATUSES, SKIP_REASONS, type ConfirmationsCard } from '@/types/orderConfirmations';

beforeAll(() => setCurrentLocale('ar'));

describe('ConfirmationStatusChip', () => {
    it('labels every status in both languages and gives each a tone', () => {
        for (const status of CONFIRMATION_STATUSES) {
            expect(ar.order_confirmations.statuses[status]).toBeTruthy();
            expect(en.order_confirmations.statuses[status]).toBeTruthy();
            expect(CONFIRMATION_TONE[status]).toBeTruthy();
        }
        expect(mount(ConfirmationStatusChip, { props: { status: 'cancel_requested' } }).text()).toBe('طلبت إلغاء');
    });

    it('carries the skip reason on hover and for screen readers', () => {
        const w = mount(ConfirmationStatusChip, { props: { status: 'skipped', skipReason: 'too_old' } });
        expect(w.find('[data-confirmation-chip]').attributes('title')).toBe('الأوردر قديم');
        expect(w.find('.sr-only').text()).toBe('الأوردر قديم');
    });

    it('shows the send error of a failed one, and nothing extra otherwise', () => {
        expect(mount(ConfirmationStatusChip, { props: { status: 'failed', lastError: 'template_not_found' } }).attributes('title')).toBe(
            'template_not_found',
        );
        expect(
            mount(ConfirmationStatusChip, { props: { status: 'sent' } })
                .find('.sr-only')
                .exists(),
        ).toBe(false);
    });
});

describe('skip reasons', () => {
    it('translates every App\\Confirmations\\SkipReason and shows an unknown one raw', () => {
        const { t } = useI18n();
        for (const reason of SKIP_REASONS) {
            expect(ar.order_confirmations.skip_reasons[reason]).toBeTruthy();
            expect(en.order_confirmations.skip_reasons[reason]).toBeTruthy();
        }
        expect(skipReasonLabel(t, 'no_phone')).toBe('مفيش رقم موبايل صالح');
        expect(skipReasonLabel(t, 'something_new')).toBe('something_new');
        expect(skipReasonLabel(t, null)).toBe('');
    });
});

describe('ConfirmationTimeline', () => {
    it('lists the steps reached with their details', () => {
        const w = mount(ConfirmationTimeline, {
            props: {
                steps: [
                    { key: 'scheduled', at: '2026-10-16T09:00:00Z', detail: null },
                    { key: 'sent', at: '2026-10-16T09:03:00Z', detail: null },
                    { key: 'no_response', at: null, detail: null },
                    { key: 'called', at: '2026-10-17T10:00:00Z', detail: 'unreachable' },
                ],
            },
        });
        const steps = w.findAll('[data-step]');
        expect(steps.map((s) => s.attributes('data-step'))).toEqual(['scheduled', 'sent', 'no_response', 'called']);
        expect(steps[3].text()).toContain('اتكلمنا معاها: مابتردش');
        expect(steps[2].findAll('.tabular-nums')).toHaveLength(0); // no time stored for "no response"
    });

    it('names the answer and the skip reason', () => {
        const w = mount(ConfirmationTimeline, {
            props: { steps: [{ key: 'answered', at: '2026-10-16T09:10:00Z', detail: 'edit' }] },
        });
        expect(w.text()).toContain('ردت: طلبت تعديل');
        expect(mount(ConfirmationTimeline, { props: { steps: [{ key: 'skipped', at: null, detail: 'chat_order' }] } }).text()).toContain(
            'ماتبعتتش: أوردر من الشات',
        );
    });
});

describe('CallOutcomeActions', () => {
    beforeEach(() => {
        post.mockReset();
        push.mockReset();
    });

    it('posts the outcome and hands back the updated row', async () => {
        post.mockResolvedValue({ data: { data: { id: 9, status: 'unreachable' } } });
        const w = mount(CallOutcomeActions, { props: { confirmationId: 9 } });

        expect(w.findAll('[data-call]').map((b) => b.attributes('aria-label'))).toEqual(['أكدت في المكالمة', 'لغت في المكالمة', 'مابتردش']);
        await w.find('[data-call="unreachable"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/orders/confirmations/9/call', { outcome: 'unreachable' });
        expect(w.emitted('recorded')?.[0]).toEqual([{ id: 9, status: 'unreachable' }]);
        expect(push).toHaveBeenCalledWith('اتسجلت نتيجة المكالمة: مابتردش');
    });

    it('shows the server error and emits nothing when it fails', async () => {
        post.mockRejectedValue(new Error('422'));
        const w = mount(CallOutcomeActions, { props: { confirmationId: 9 } });
        await w.find('[data-call="confirmed"]').trigger('click');
        await flushPromises();

        expect(w.emitted('recorded')).toBeUndefined();
        expect(push).toHaveBeenCalledWith(expect.any(String), 'error');
    });
});

describe('confirmationsRows (النهارده)', () => {
    const card: ConfirmationsCard = {
        sent: 12,
        confirmed: 6,
        confirmed_share: 0.75,
        cancel: 1,
        edit: 1,
        no_response: 0,
        waiting: 4,
        needs_call: 3,
        links: {
            sent: '/orders/confirmations?date=2026-10-16',
            confirmed: '/orders/confirmations?date=2026-10-16&tab=confirmed',
            requests: '/orders/confirmations?date=2026-10-16&tab=requests',
            needs_call: '/orders/confirmations?tab=needs_call',
        },
    };

    it('links each number to its tab and flags the call list', () => {
        const { t } = useI18n();
        const rows = confirmationsRows(card, t, 'en');
        expect(rows.map((r) => r.key)).toEqual(['sent', 'confirmed', 'requests', 'waiting', 'needs_call']);
        expect(rows.find((r) => r.key === 'confirmed')?.value).toBe('6 (75%)');
        const call = rows.find((r) => r.key === 'needs_call');
        expect(call?.href).toBe('/orders/confirmations?tab=needs_call');
        expect(call?.tone).toBe('bad');
        expect(confirmationsRows({ ...card, needs_call: 0 }, t, 'en').find((r) => r.key === 'needs_call')?.tone).toBe('default');
    });
});
