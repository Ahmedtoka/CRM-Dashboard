import type { Conversation, InboxFilters, InboxQuickFilter } from '@/types/crm';

/**
 * Client-side mirror of App\Inbox\ConversationQuery's filter and ordering rules,
 * applied to realtime patches and merged pages so the list never contradicts the server.
 * Pure functions (no Vue state) so they stay easy to reason about and to test.
 */

/** Filters the server orders by handover priority (ConversationQuery::build). */
export const PRIORITY_ORDERED_FILTERS: readonly InboxQuickFilter[] = ['needs_human', 'queue_all', 'queue_high', 'queue_senior'];

/** Filters that also hide low-value conversations (ConversationQuery: priority != low). */
const EXCLUDES_LOW: readonly InboxQuickFilter[] = ['waiting', 'needs_human', 'queue_all', 'queue_high', 'queue_senior'];

const PRIORITY_RANK: Record<string, number> = { high: 0, medium: 1, low: 2 };

const time = (iso: string | null | undefined, empty: number) => (iso ? Date.parse(iso) : empty);

export function priorityRank(level: Conversation['priority_level'] | undefined): number {
    return level ? (PRIORITY_RANK[level] ?? 3) : 3;
}

/**
 * Sort comparator for the active quick filter:
 * - queues / needs_human: priority high → medium → low → none, oldest customer message first, then id
 * - waiting: oldest waiting first
 * - everything else: newest activity first
 * Null timestamps sort last in ascending orders (the server's sqlite/mysql NULL order differs by engine).
 */
export function compareConversations(filter: InboxFilters['filter']): (a: Conversation, b: Conversation) => number {
    if (filter && PRIORITY_ORDERED_FILTERS.includes(filter)) {
        return (a, b) => {
            const rank = priorityRank(a.priority_level) - priorityRank(b.priority_level);
            if (rank !== 0) return rank;
            const ta = time(a.last_customer_message_at, Number.MAX_SAFE_INTEGER);
            const tb = time(b.last_customer_message_at, Number.MAX_SAFE_INTEGER);
            return ta !== tb ? ta - tb : a.id - b.id;
        };
    }

    const waiting = filter === 'waiting';

    return (a, b) => {
        if (waiting) {
            const wa = time(a.waiting_since, Number.MAX_SAFE_INTEGER);
            const wb = time(b.waiting_since, Number.MAX_SAFE_INTEGER);
            if (wa !== wb) return wa - wb;
        }
        const la = time(a.last_message_at, 0);
        const lb = time(b.last_message_at, 0);
        return la !== lb ? lb - la : b.id - a.id;
    };
}

/** Whether a (patched) conversation still belongs in the list for these filters. */
export function matchesInboxFilters(c: Conversation, f: InboxFilters): boolean {
    if (f.filter === 'spam') return c.priority === 'spam';
    if (c.priority === 'spam') return false; // hidden everywhere except the spam filter
    if (f.filter === 'low_priority') return c.priority === 'low';
    if (f.filter && EXCLUDES_LOW.includes(f.filter) && c.priority === 'low') return false;
    if (f.platform && c.platform !== f.platform) return false;
    if (f.status && c.status !== f.status) return false;
    if ((f.filter === 'needs_human' || f.filter === 'queue_all') && !c.needs_human) return false;
    if (f.filter === 'queue_high' && !(c.needs_human && c.priority_level === 'high')) return false;
    if (f.filter === 'queue_senior' && !(c.needs_human && c.queue === 'senior')) return false;
    if (f.filter === 'bot' && c.handler !== 'bot') return false;
    if (f.filter === 'waiting' && !c.waiting_since) return false;
    if (f.tag && !(c.tags ?? []).some((t) => t.id === f.tag)) return false;

    return true;
}
