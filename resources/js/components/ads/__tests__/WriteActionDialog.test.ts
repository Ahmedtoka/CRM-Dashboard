import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ post }),
    apiErrorMessage: (e: { response?: { data?: { message?: string } } }, f: string) => e?.response?.data?.message ?? f,
}));

import WriteActionDialog from '@/components/ads/WriteActionDialog.vue';
import { setCurrentLocale } from '@/composables/useI18n';

beforeAll(() => setCurrentLocale('en'));

const FormDialogStub = {
    name: 'FormDialog',
    props: ['open', 'title', 'busy', 'error', 'destructive', 'disabled', 'submitLabel'],
    emits: ['submit', 'update:open'],
    template: '<form @submit.prevent="$emit(\'submit\')"><slot /><button type="submit">{{ submitLabel }}</button></form>',
};
const proposal = {
    action: { id: 'wa_1', state: 'proposed', from_status: 'PAUSED', to: 'active', name: 'Ad', account: 'Acc', expires_at: null },
    diff: [{ path: 'status', before: 'PAUSED', after: 'ACTIVE' }],
    diff_hash: 'h'.repeat(64),
    notes: [],
};
const props = { open: false, accountId: 1, account: 'Acc', platform: 'meta', level: 'ad', externalId: '1', name: 'Ad', to: 'active' };

describe('WriteActionDialog', () => {
    beforeEach(() => post.mockReset());

    it('offers «try again» after a failure', async () => {
        post.mockRejectedValueOnce({ response: { status: 409, data: { message: 'changed' } } });
        const w = mount(WriteActionDialog, { props: props as never, global: { stubs: { FormDialog: FormDialogStub } } });
        await w.setProps({ open: true });
        await flushPromises();
        expect(w.findComponent({ name: 'FormDialog' }).props('submitLabel')).toBe('Try again');
    });

    it('focuses the password field when a Run needs the password', async () => {
        post.mockResolvedValueOnce({ status: 201, data: proposal }).mockRejectedValueOnce({ response: { status: 423, data: { code: 'password_confirmation_required' } } });
        const w = mount(WriteActionDialog, { props: props as never, global: { stubs: { FormDialog: FormDialogStub } }, attachTo: document.body });
        await w.setProps({ open: true });
        await flushPromises();
        await w.find('form').trigger('submit');
        await flushPromises();
        const input = w.find('[data-test="reauth-password"]');
        expect(input.exists()).toBe(true);
        expect(document.activeElement).toBe(input.element);
        w.unmount();
    });
});

describe('WriteActionDialog round 3', () => {
    beforeEach(() => post.mockReset());

    it('shows a budget change as money before and after, and the dry-run banner when execution is off', async () => {
        post.mockResolvedValueOnce({
            status: 201,
            data: {
                action: { id: 'wa_2', state: 'proposed', type: 'set_budget', from_status: 'ACTIVE', to: null, name: 'Set A', account: 'Acc', expires_at: null, params: { pct: 20, from_minor: 50000, to_minor: 60000, currency: 'EGP' } },
                diff: [{ path: 'daily_budget', level: 'adset', before: { minor: 50000, currency: 'EGP' }, after: { minor: 60000, currency: 'EGP' } }],
                diff_hash: 'h'.repeat(64),
                notes: [],
                execute: false,
            },
        });
        const w = mount(WriteActionDialog, { props: { ...props, type: 'set_budget', pct: 20, to: null } as never, global: { stubs: { FormDialog: FormDialogStub } } });
        await w.setProps({ open: true });
        await flushPromises();
        expect(post.mock.calls[0][1]).toMatchObject({ type: 'set_budget', params: { pct: 20 } });
        expect(w.text()).toContain('500');
        expect(w.text()).toContain('600');
        expect(w.find('[data-test="dry-run"]').exists()).toBe(true);
        expect(w.findComponent({ name: 'FormDialog' }).props('submitLabel')).toBe('Yes, change the budget');
    });

    it('undo mode proposes the inverse through the rollback endpoint', async () => {
        post.mockResolvedValueOnce({ status: 201, data: { ...proposal, execute: true, action: { ...proposal.action, rollback_of: 'wa_1' } } });
        const w = mount(WriteActionDialog, { props: { ...props, undoOf: 'wa_1' } as never, global: { stubs: { FormDialog: FormDialogStub } } });
        await w.setProps({ open: true });
        await flushPromises();
        expect(post.mock.calls[0][0]).toBe('/ads/write-actions/wa_1/rollback');
        expect(w.findComponent({ name: 'FormDialog' }).props('submitLabel')).toBe('Yes, undo it');
    });
});
