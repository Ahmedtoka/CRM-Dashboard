import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const { handlers } = vi.hoisted(() => ({ handlers: {} as Record<string, (e: unknown) => void> }));
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (name: string, fn: (e: unknown) => void) => {
            handlers[name] = fn;
            return () => delete handlers[name];
        },
    },
}));

import { usePathVisitLoading } from '@/composables/usePathVisitLoading';

describe('usePathVisitLoading', () => {
    it('is true only during a visit to its own path', () => {
        let loading = { value: false };
        const C = defineComponent({
            setup() {
                loading = usePathVisitLoading('/ads');
                return () => h('div');
            },
        });
        const w = mount(C);
        handlers.start({ detail: { visit: { url: new URL('http://x.test/ads/explorer') } } });
        expect(loading.value).toBe(false);
        handlers.start({ detail: { visit: { url: new URL('http://x.test/ads?accounts=3') } } });
        expect(loading.value).toBe(true);
        handlers.finish({});
        expect(loading.value).toBe(false);
        w.unmount();
        expect(handlers.start).toBeUndefined();
    });
});
