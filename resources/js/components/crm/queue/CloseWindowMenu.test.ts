import CloseWindowMenu from '@/components/crm/queue/CloseWindowMenu.vue';
import { INBOX_OUTCOME } from '@/composables/inbox/useConversationContext';
import type { ConversationQueueEntry } from '@/types/crm';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';

const push = vi.fn();
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push }) }));

const closeEntry = vi.fn(async () => true);
vi.mock('@/composables/useMyQueue', () => ({
    useMyQueueContext: () => ({ busy: ref(null), leaderUserId: ref(null), closeEntry, escalate: vi.fn() }),
}));

const entry = { id: 41, ticket: 100007, assigned_user_id: 3 } as unknown as ConversationQueueEntry;

const mountMenu = (state: { auto: string | null } | null) =>
    mount(CloseWindowMenu, {
        props: { entry },
        global: { provide: { [INBOX_OUTCOME as symbol]: computed(() => (state ? { current: null, source: null, ...state } : null)) } },
        attachTo: document.body,
    });

async function openMenu(w: ReturnType<typeof mountMenu>): Promise<void> {
    (w.vm as unknown as { open: () => void }).open();
    await flushPromises();
}

const item = (reason: string) => document.body.querySelector<HTMLElement>(`[data-reason="${reason}"]`)!;
const menu = () => document.body.querySelector<HTMLElement>('[role="menu"]')!;

afterEach(() => {
    document.body.innerHTML = '';
    closeEntry.mockClear();
    push.mockClear();
});

describe('CloseWindowMenu outcome row', () => {
    it('refuses a reason without an outcome: no request, the menu stays open', async () => {
        const w = mountMenu({ auto: null });
        await openMenu(w);
        item('inquiry').click();
        await flushPromises();

        expect(closeEntry).not.toHaveBeenCalled();
        expect(push).toHaveBeenCalledWith('اختاري نتيجة المحادثة الأول', 'error');
        expect((w.vm as unknown as { isOpen: () => boolean }).isOpen()).toBe(true);
        w.unmount();
    });

    it('picks with a real digit keydown inside the open menu, then closes with that outcome', async () => {
        const w = mountMenu({ auto: null });
        await openMenu(w);
        menu().dispatchEvent(new KeyboardEvent('keydown', { key: '2', bubbles: true, cancelable: true }));
        await flushPromises();
        expect(document.body.querySelector('[data-outcome="size_out"]')?.getAttribute('aria-checked')).toBe('true');

        item('inquiry').click();
        await flushPromises();
        expect(closeEntry).toHaveBeenCalledWith(41, 'inquiry', null, { outcome: 'size_out' });
        w.unmount();
    });

    it('sends nothing while the outcome state is loading', async () => {
        const w = mountMenu(null);
        await openMenu(w);
        expect(document.body.querySelector('[data-outcome-loading]')).not.toBeNull();
        menu().dispatchEvent(new KeyboardEvent('keydown', { key: '1', bubbles: true, cancelable: true }));
        item('inquiry').click();
        await flushPromises();
        expect(closeEntry).not.toHaveBeenCalled();
        w.unmount();
    });

    it('closes an ordered chat without a pick', async () => {
        const w = mountMenu({ auto: 'ordered' });
        await openMenu(w);
        item('problem').click();
        await flushPromises();
        expect(closeEntry).toHaveBeenCalledWith(41, 'problem', null, {});
        w.unmount();
    });
});
