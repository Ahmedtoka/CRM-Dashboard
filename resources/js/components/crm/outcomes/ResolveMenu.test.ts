import ResolveMenu from '@/components/crm/outcomes/ResolveMenu.vue';
import { INBOX_OUTCOME } from '@/composables/inbox/useConversationContext';
import type { OutcomePayload } from '@/types/crm';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { computed, nextTick } from 'vue';

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push: vi.fn() }) }));

type Menu = { submit: () => boolean; open: () => void; isOpen: () => boolean };
type Done = (error: string | null) => void;

const mountMenu = (auto: string | null, loading = false) =>
    mount(ResolveMenu, {
        props: {},
        global: { provide: { [INBOX_OUTCOME as symbol]: computed(() => (loading ? null : { current: null, source: null, auto })) } },
        attachTo: document.body,
    });

const resolves = (w: ReturnType<typeof mountMenu>) => (w.emitted('resolve') ?? []) as [OutcomePayload, Done][];

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
        expect(resolves(w)[0][0]).toEqual({});
        w.unmount();
    });

    it('resolves with an untouched automatic service and nothing to send', () => {
        const w = mountMenu('service');
        expect((w.vm as unknown as Menu).submit()).toBe(true);
        expect(resolves(w)[0][0]).toEqual({});
        w.unmount();
    });

    it('sends nothing while the outcome state is still loading', async () => {
        const w = mountMenu(null, true);
        (w.vm as unknown as Menu).open();
        await flushPromises();
        expect(document.body.querySelector('[data-outcome-loading]')).not.toBeNull();
        expect((w.vm as unknown as Menu).submit()).toBe(false);
        expect(w.emitted('resolve')).toBeUndefined();
        w.unmount();
    });

    it('stays open with the server message on a refusal, closes on success', async () => {
        const w = mountMenu('ordered');
        const vm = w.vm as unknown as Menu;
        vm.open();
        await flushPromises();
        vm.submit();
        resolves(w)[0][1]('اختاري نتيجة المحادثة الأول');
        await nextTick();
        expect(vm.isOpen()).toBe(true);
        expect(document.body.querySelector('[data-resolve-error]')?.textContent).toContain('اختاري نتيجة المحادثة الأول');

        vm.submit();
        resolves(w)[1][1](null);
        await nextTick();
        expect(vm.isOpen()).toBe(false);
        w.unmount();
    });
});
