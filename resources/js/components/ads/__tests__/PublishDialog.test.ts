import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('@/composables/useApi', () => ({
    useApi: () => ({ get, post }),
    apiErrorMessage: (e: { response?: { data?: { message?: string } } }, f: string) => e?.response?.data?.message ?? f,
}));
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push: vi.fn() }) }));

import PublishDialog from '@/components/ads/PublishDialog.vue';
import { setCurrentLocale } from '@/composables/useI18n';

beforeAll(() => setCurrentLocale('en'));

const FormDialogStub = {
    name: 'FormDialog',
    props: ['open', 'title', 'description', 'busy', 'error', 'disabled', 'submitLabel', 'wide'],
    emits: ['submit', 'update:open'],
    template: '<form @submit.prevent="$emit(\'submit\')"><slot /><button type="submit" :disabled="disabled">{{ submitLabel }}</button></form>',
};
const material = { id: 7, title: 'Silk reel', types: ['reel'], content_notes: 'Soft silk', files: [{ id: 3, mime: 'video/mp4', thumb_url: null }] };

function options(config: { params: { material?: number; account?: number } }) {
    if (config.params.material) return Promise.resolve({ data: { accounts: [{ id: 1, name: 'LV Main', platform: 'meta' }], link: 'https://store/products/x' } });
    return Promise.resolve({
        data: {
            campaigns: [{ id: 'c1', name: 'Camp', adsets: [{ id: 's1', name: 'Set' }] }],
            identities: [{ page_id: 'p1', page_name: 'Le Voile', instagram_id: null }],
            last_identity: null,
        },
    });
}

describe('PublishDialog', () => {
    beforeEach(() => {
        get.mockReset().mockImplementation((_url: string, config: never) => options(config));
        post.mockReset();
    });

    it('A10: sends one publish for two quick submits and disables the button while it is in flight', async () => {
        let resolve: (v: unknown) => void = () => {};
        post.mockImplementation(() => new Promise((r) => (resolve = r)));
        const w = mount(PublishDialog, { props: { open: true, material: material as never }, global: { stubs: { FormDialog: FormDialogStub } } });
        await flushPromises();
        await w.find('#pub-campaign').setValue('c1');
        await w.find('#pub-adset').setValue('s1');
        await flushPromises();

        await w.find('form').trigger('submit');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(w.findComponent({ name: 'FormDialog' }).props('disabled')).toBe(true);
        resolve({ data: { message: 'queued' } });
        await flushPromises();
        expect(w.emitted('published')).toHaveLength(1);
    });
});
