import ConversationItem from '@/components/crm/ConversationItem.vue';
import { loadTestName } from '@/lib/loadTest';
import type { Conversation } from '@/types/crm';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const base = {
    id: 1,
    platform: 'whatsapp',
    status: 'open',
    priority: 'normal',
    priority_level: null,
    queue: null,
    handover_category: null,
    handover_category_label: null,
    handler: 'human',
    needs_human: true,
    source: 'direct',
    is_test: false,
    unread_count: 0,
    last_message_at: '2026-10-08T10:00:00Z',
    last_customer_message_at: '2026-10-08T10:00:00Z',
    waiting_since: null,
    last_message_preview: 'الأوردر اتأخر',
    customer: { id: 1, name: 'منى', avatar_url: null },
    locked_by: null,
    first_responder: null,
    tags: [],
    handling: null,
    assignee: null,
    queue_entry: null,
    open_case_id: null,
    can: { reply: true },
} as unknown as Conversation;

const mountRow = (c: Conversation) =>
    mount(ConversationItem, { props: { conversation: c, state: null }, global: { stubs: { PlatformBadge: true } } });

describe('load-test chip', () => {
    it('marks a load-test chat «تيست»', () => {
        const w = mountRow({ ...base, is_load_test: true });
        expect(w.find('[data-load-test-chip]').text()).toBe('تيست');
        w.unmount();
    });

    it('shows nothing on a real chat', () => {
        const w = mountRow(base);
        expect(w.find('[data-load-test-chip]').exists()).toBe(false);
        w.unmount();
    });

    it('prefixes the board name only for a load-test chat', () => {
        const t = (k: string) => (k === 'inbox.load_test_badge' ? 'تيست' : k);
        expect(loadTestName('منى', true, t)).toBe('تيست · منى');
        expect(loadTestName('منى', false, t)).toBe('منى');
    });
});
