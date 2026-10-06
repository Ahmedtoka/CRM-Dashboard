import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ get }) }));

import { useAdFunnel } from '@/composables/useAdFunnel';
import { effectScope, ref } from 'vue';

const row = { chats: 3, to_agent: 1, orders: 1, delivered: 0, returned: 0, reasons: {} };

describe('useAdFunnel', () => {
    it('loads the open ad once, silently, for the page range and clears it when the drawer closes', async () => {
        get.mockResolvedValueOnce({ data: { data: { 7: row } } });
        const id = ref<number | null>(7);
        const scope = effectScope();
        const f = scope.run(() => useAdFunnel(() => id.value, () => ({ from: '2026-10-01', to: '2026-10-07' })))!;
        expect(f.loading.value).toBe(true);
        await flushPromises();
        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith('/ads/chat-funnel', { params: { ads: [7], from: '2026-10-01', to: '2026-10-07' }, silent: true });
        expect(f.funnel.value?.chats).toBe(3);
        expect(f.loading.value).toBe(false);

        id.value = null;
        await flushPromises();
        expect(f.funnel.value).toBeNull();
        expect(get).toHaveBeenCalledTimes(1);
        scope.stop();
    });

    it('reports a failure and retries, and drops a late answer for another ad', async () => {
        get.mockReset();
        let late: (v: unknown) => void = () => {};
        get.mockRejectedValueOnce(new Error('x'))
            .mockResolvedValueOnce({ data: { data: { 7: row } } })
            .mockImplementationOnce(() => new Promise((r) => (late = r)))
            .mockResolvedValueOnce({ data: { data: { 9: { ...row, chats: 9 } } } });
        const id = ref<number | null>(7);
        const scope = effectScope();
        const f = scope.run(() => useAdFunnel(() => id.value, () => ({ from: null, to: null })))!;
        await flushPromises();
        expect(f.error.value).toBe(true);
        f.retry();
        await flushPromises();
        expect(f.error.value).toBe(false);
        expect(f.funnel.value?.chats).toBe(3);

        id.value = 8;
        await flushPromises();
        id.value = 9;
        await flushPromises();
        late({ data: { data: { 8: { ...row, chats: 8 } } } });
        await flushPromises();
        expect(f.funnel.value?.chats).toBe(9);
        scope.stop();
    });
});
