import { useConversationContext } from '@/composables/inbox/useConversationContext';
import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';

const get = vi.fn();
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ get }), apiErrorMessage: (_e: unknown, f: string) => f }));

const ctx = (auto: string | null) => ({ data: { data: { outcome: { current: null, source: null, auto }, handover: null, ad: null } } });

describe('useConversationContext', () => {
    it('loads per chat and drops a late answer for the chat she left', async () => {
        let resolveFirst: (v: unknown) => void = () => {};
        get.mockImplementationOnce(() => new Promise((r) => (resolveFirst = r))).mockResolvedValueOnce(ctx('ordered'));
        const id = ref<number | null>(1);
        const scope = effectScope();
        const c = scope.run(() => useConversationContext(id))!;

        id.value = 2;
        await nextTick();
        await flushPromises();
        resolveFirst(ctx('service'));
        await flushPromises();

        expect(get).toHaveBeenLastCalledWith('/inbox/conversations/2/context', expect.anything());
        expect(c.context.value?.outcome.auto).toBe('ordered');
        scope.stop();
    });

    it('marks ordered locally after an order is placed', async () => {
        get.mockResolvedValueOnce(ctx(null));
        const scope = effectScope();
        const c = scope.run(() => useConversationContext(ref(5)))!;
        await flushPromises();
        c.markOrdered();
        expect(c.context.value?.outcome.auto).toBe('ordered');
        scope.stop();
    });

    it('holds nothing without a chat', async () => {
        const scope = effectScope();
        const c = scope.run(() => useConversationContext(ref(null)))!;
        await flushPromises();
        expect(c.context.value).toBeNull();
        scope.stop();
    });
});
