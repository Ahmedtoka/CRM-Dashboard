import ConversationItem from '@/components/crm/ConversationItem.vue';
import type { Conversation } from '@/types/crm';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

const base = {
    id: 1,
    platform: 'facebook',
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
    waiting_since: '2026-10-08T10:00:00Z',
    last_message_preview: 'بكام؟',
    customer: { id: 1, name: 'نور', avatar_url: null },
    locked_by: null,
    first_responder: null,
    tags: [],
    handling: null,
    assignee: null,
    queue_entry: null,
    open_case_id: null,
    can: { reply: true },
} as unknown as Conversation;

afterEach(() => vi.useRealTimers());

describe('ConversationItem waiting age', () => {
    it('replaces the time with «مستنية ٧ د» in amber after the target', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-08T10:07:00Z'));
        const w = mount(ConversationItem, { props: { conversation: base, state: null, firstReplyTarget: 300 }, global: { stubs: { PlatformBadge: true } } });
        const chip = w.find('[data-waiting-age]');
        expect(chip.text()).toBe('مستنية ٧ د');
        expect(chip.classes().join(' ')).toContain('amber');
        w.unmount();
    });

    it('keeps the time before the target', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-08T10:02:00Z'));
        const w = mount(ConversationItem, { props: { conversation: base, state: null, firstReplyTarget: 300 }, global: { stubs: { PlatformBadge: true } } });
        expect(w.find('[data-waiting-age]').exists()).toBe(false);
        w.unmount();
    });
});
