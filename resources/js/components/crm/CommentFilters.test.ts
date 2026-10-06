import CommentFilters from '@/components/crm/CommentFilters.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { role: 'admin', platforms: [] } }, platforms: [] } }),
        router: { get: vi.fn(), replace: vi.fn(), push: vi.fn(), on: vi.fn(() => () => {}) },
    };
});

describe('CommentFilters', () => {
    it('«مسح الكل» turns «الإعلانات فقط» off before it asks for the cleared filters (the visit must not carry ad=1)', async () => {
        const order: string[] = [];
        const w = mount(CommentFilters, {
            props: {
                filters: { status: 'new', intent: null, platform: null, post_id: null },
                adOnly: true,
                'onUpdate:adOnly': (value: boolean) => order.push(`adOnly:${value}`),
                'onUpdate:filters': () => order.push('filters'),
            },
        });

        // FilterBar's «مسح الكل» is the underlined link-button after the chips.
        const clearAll = w.findAll('[role="group"] button').find((b) => b.classes().includes('hover:underline'));
        expect(clearAll, 'clear-all button').toBeDefined();
        await clearAll!.trigger('click');

        expect(order).toEqual(['adOnly:false', 'filters']);
    });
});
