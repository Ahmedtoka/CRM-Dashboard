import { compareConversations, matchesInboxFilters } from '@/lib/inboxListOrder';
import type { Conversation, InboxFilters } from '@/types/crm';
import { describe, expect, it } from 'vitest';

const f = { status: null, queue: null, assignee: null, flags: [], platform: null, tag: null, q: null, sort: 'oldest_waiting' } as InboxFilters;
const row = (id: number, at: string) => ({ id, last_customer_message_at: at, last_message_at: at, status: 'open', priority: 'normal', tags: [] }) as unknown as Conversation;

describe('oldest waiting order', () => {
    it('sorts by the oldest customer message first', () => {
        const rows = [row(1, '2026-10-08T10:05:00Z'), row(2, '2026-10-08T09:00:00Z')];
        expect(rows.sort(compareConversations(f)).map((r) => r.id)).toEqual([2, 1]);
    });

    it('leaves membership to the server, but drops a resolved row', () => {
        expect(matchesInboxFilters(row(1, '2026-10-08T10:05:00Z'), f)).toEqual({ keep: true, undecided: true });
        expect(matchesInboxFilters({ ...row(1, '2026-10-08T10:05:00Z'), status: 'resolved' }, f).keep).toBe(false);
    });
});
