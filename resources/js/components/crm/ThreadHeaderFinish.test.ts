import ThreadHeader from '@/components/crm/ThreadHeader.vue';
import type { Conversation } from '@/types/crm';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const role = ref('moderator');
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return { ...actual, usePage: () => ({ props: { auth: { user: { id: 3, role: role.value } } } }) };
});

const get = vi.fn(async (): Promise<{ data: { data: unknown[] } }> => ({ data: { data: [] } }));
vi.mock('@/composables/useApi', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/composables/useApi')>();

    return { ...actual, useApi: () => ({ get, post: vi.fn() }) };
});
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ push: vi.fn() }) }));

const queue = {
    enabled: ref(true),
    leaderUserId: ref<number | null>(null),
    busy: ref<string | null>(null),
    entryOf: () => null,
    escalate: vi.fn(async () => true),
    elapsed: () => 0,
    silenceLeft: () => null,
    silenceWarning: () => false,
    silenceTotal: ref(null),
    handoffLeft: () => null,
    entries: ref([]),
};
vi.mock('@/composables/useMyQueue', () => ({ useMyQueueContext: () => queue }));

const conv = (over: Partial<Conversation> = {}): Conversation =>
    ({
        id: 9,
        platform: 'messenger',
        status: 'open',
        priority: 'normal',
        priority_level: null,
        queue: null,
        handler: 'bot',
        needs_human: false,
        source: null,
        is_test: false,
        unread_count: 0,
        tags: [],
        handling: null,
        assignee: { id: 7, name: 'زينب' },
        queue_entry: null,
        queue_state: null,
        open_case_id: null,
        customer: { id: 1, name: 'رشا', avatar_url: null },
        can: { reply: true },
        ...over,
    }) as unknown as Conversation;

const windowOf = (assigned: number) =>
    ({ id: 41, assigned_user_id: assigned, ticket: 100008, window_no: 8, status: 'active', open_case_id: null }) as unknown as Conversation['queue_entry'];

function mountHeader(conversation: Conversation) {
    return mount(ThreadHeader, {
        props: { conversation, viewers: [], meId: 3, typing: [], tags: [], busyAction: null },
        attachTo: document.body,
    });
}

type Exposed = { openCloseWindow: (mode?: 'close' | 'bot') => string; openResolve: () => boolean; holder: () => string; closeMenuOpen: () => boolean };

afterEach(() => {
    document.body.innerHTML = '';
    role.value = 'moderator';
    queue.leaderUserId.value = null;
    get.mockClear();
});

describe('ThreadHeader primary action (close cycle)', () => {
    it('her open window: one «خلصت» that opens the sheet; no reopen, no old menus', async () => {
        const w = mountHeader(conv({ handler: 'human', queue_entry: windowOf(3) }));
        expect(w.find('[data-finish-open]').exists()).toBe(true);
        expect(w.find('[data-close-window]').exists()).toBe(false);
        expect(w.find('[data-resolve-menu]').exists()).toBe(false);
        expect(w.text()).not.toContain('فتح تاني');

        await w.find('[data-finish-open]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-finish-sheet]')?.textContent).toContain('شباك #٨ هيفضى');
        expect((w.vm as unknown as Exposed).closeMenuOpen()).toBe(true);
        w.unmount();
    });

    it('somebody else’s window: the lock chip, and the shortcuts are blocked with her name', () => {
        const w = mountHeader(conv({ handler: 'human', queue_entry: windowOf(7) }));
        expect(w.find('[data-finish-open]').exists()).toBe(false);
        expect(w.find('[data-held-by]').exists()).toBe(true);
        expect((w.vm as unknown as Exposed).openCloseWindow()).toBe('blocked');
        expect((w.vm as unknown as Exposed).holder()).toBe('زينب');
        w.unmount();
    });

    it('a supervisor gets «خلصت» on a colleague’s window', () => {
        role.value = 'supervisor';
        const w = mountHeader(conv({ handler: 'human', queue_entry: windowOf(7) }));
        expect(w.find('[data-finish-open]').exists()).toBe(true);
        w.unmount();
    });

    it('a customer in the lounge, and a human chat with no window', () => {
        const lounge = mountHeader(conv({ queue_state: { id: 77, status: 'waiting', ticket: 12, priority: 'live', overdue: false, assigned_user_id: null } }));
        expect(lounge.find('[data-finish-open]').exists()).toBe(true);
        lounge.unmount();

        const human = mountHeader(conv({ handler: 'human' }));
        expect(human.find('[data-finish-open]').exists()).toBe(true);
        human.unmount();
    });

    it('a bot-held or resolved chat: no primary action, the shortcuts find nothing to finish', () => {
        const bot = mountHeader(conv());
        expect(bot.find('[data-primary-action]').exists()).toBe(false);
        expect((bot.vm as unknown as Exposed).openCloseWindow()).toBe('none');
        expect((bot.vm as unknown as Exposed).openResolve()).toBe(false);
        bot.unmount();

        const resolved = mountHeader(conv({ handler: 'human', status: 'resolved' }));
        expect(resolved.find('[data-primary-action]').exists()).toBe(false);
        resolved.unmount();
    });

    it('«رجوع للبوت» (the `b` shortcut) opens the sheet on «البوت يكمّل معاها»', async () => {
        const w = mountHeader(conv({ handler: 'human', queue_entry: windowOf(3) }));
        expect((w.vm as unknown as Exposed).openCloseWindow('bot')).toBe('menu');
        await flushPromises();
        expect(document.body.querySelector('[data-after="bot"]')?.getAttribute('aria-pressed')).toBe('true');
        expect(document.body.querySelector('[data-finish-closing]')).toBeNull();
        w.unmount();
    });

    it('«حددي النتيجة» shows when /queue/me/auto-closed lists this chat', async () => {
        get.mockResolvedValueOnce({ data: { data: [{ entry_id: 5, conversation_id: 9, ticket: 3, customer_name: 'رشا', closed_at: null }] } });
        const w = mountHeader(conv());
        await flushPromises();
        expect(get).toHaveBeenCalledWith('/queue/me/auto-closed', { silent: true });
        expect(w.find('[data-amend-outcome]').exists()).toBe(true);
        w.unmount();
    });
});
