import ResolveMenu from '@/components/crm/outcomes/ResolveMenu.vue';
import { INBOX_OUTCOME } from '@/composables/inbox/useConversationContext';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { computed } from 'vue';

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push: vi.fn() }) }));

type Menu = { submit: () => boolean };

const mountMenu = (auto: string | null) =>
    mount(ResolveMenu, {
        props: {},
        global: { provide: { [INBOX_OUTCOME as symbol]: computed(() => ({ current: null, source: null, auto })) } },
        attachTo: document.body,
    });

describe('ResolveMenu', () => {
    it('does not resolve before an outcome is picked', () => {
        const w = mountMenu(null);
        expect((w.vm as unknown as Menu).submit()).toBe(false);
        expect(w.emitted('resolve')).toBeUndefined();
        w.unmount();
    });

    it('resolves with nothing to send when the outcome is automatic', () => {
        const w = mountMenu('ordered');
        expect((w.vm as unknown as Menu).submit()).toBe(true);
        expect(w.emitted('resolve')?.[0]).toEqual([{}]);
        w.unmount();
    });

    it('resolves with an untouched automatic service and nothing to send', () => {
        const w = mountMenu('service');
        expect((w.vm as unknown as Menu).submit()).toBe(true);
        expect(w.emitted('resolve')?.[0]).toEqual([{}]);
        w.unmount();
    });
});
