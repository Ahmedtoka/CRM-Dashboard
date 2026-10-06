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
