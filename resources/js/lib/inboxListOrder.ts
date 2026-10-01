import type { Conversation, InboxFilters, InboxQuickFilter } from '@/types/crm';

/**
 * Client-side mirror of App\Inbox\ConversationQuery's filter and ordering rules,
 * applied to realtime patches and merged pages so the list never contradicts the server.
 * Pure functions (no Vue state) so they stay easy to reason about and to test.
 */

/** Flags the server orders by handover priority (ConversationQuery::QUEUE_ORDER_FLAGS). */
export const PRIORITY_ORDERED_FILTERS: readonly InboxQuickFilter[] = ['needs_human', 'queue_all', 'queue_high', 'queue_senior'];

/** Flags that also hide low-value conversations (ConversationQuery::LOW_EXCLUDING_FLAGS). */
const EXCLUDES_LOW: readonly InboxQuickFilter[] = ['waiting', 'needs_human', 'queue_all', 'queue_high', 'queue_senior'];

const PRIORITY_RANK: Record<string, number> = { high: 0, medium: 1, low: 2 };

const time = (iso: string | null | undefined, empty: number) => (iso ? Date.parse(iso) : empty);

export function priorityRank(level: Conversation['priority_level'] | undefined): number {
    return level ? (PRIORITY_RANK[level] ?? 3) : 3;
}

type OrderFilters = Pick<InboxFilters, 'status' | 'queue' | 'flags'>;

/**
 * Sort comparator for the active filters (ConversationQuery::build):
 * - status=waiting or the waiting flag: oldest customer message first
 * - a queue state or a priority-ordered flag: priority high → medium → low → none, oldest customer message first, then id
 * - everything else: newest activity first
 * Null timestamps sort FIRST in ascending orders, like the server (NULL is smallest on MariaDB and sqlite).
 */
export function compareConversations(f: OrderFilters): (a: Conversation, b: Conversation) => number {
    const flags = f.flags ?? [];

    if (f.status === 'waiting' || flags.includes('waiting')) {
        return (a, b) => {
            const wa = time(a.last_customer_message_at, Number.MIN_SAFE_INTEGER);
            const wb = time(b.last_customer_message_at, Number.MIN_SAFE_INTEGER);
            return wa !== wb ? wa - wb : a.id - b.id;
        };
    }

    if (f.queue || flags.some((flag) => PRIORITY_ORDERED_FILTERS.includes(flag))) {
        return (a, b) => {
            const rank = priorityRank(a.priority_level) - priorityRank(b.priority_level);
            if (rank !== 0) return rank;
            const ta = time(a.last_customer_message_at, Number.MIN_SAFE_INTEGER);
            const tb = time(b.last_customer_message_at, Number.MIN_SAFE_INTEGER);
            return ta !== tb ? ta - tb : a.id - b.id;
        };
    }

    return (a, b) => {
        const la = time(a.last_message_at, 0);
        const lb = time(b.last_message_at, 0);
        return la !== lb ? lb - la : b.id - a.id;
    };
}

/** Flags this mirror can decide from a row alone; any other flag is left to the server. */
const LOCAL_FLAGS: readonly InboxQuickFilter[] = ['spam', 'low_priority', 'needs_human', 'queue_all', 'queue_high', 'queue_senior', 'bot', 'comment', 'ad', 'test'];

/**
 * Whether a (patched) conversation still belongs in the list for these filters:
 * - true  — keep it (it matches, or the server must decide);
 * - false — it certainly no longer matches: drop it.
 * `undecided` is set when a filter the row cannot answer is active.
 *
 * Rule (Task 5): the client only decides what the row itself says for sure — status
 * open / pending / resolved / closed / bot, platform, tag, and the plain flags in LOCAL_FLAGS.
 * For status=waiting|with_moderator, a queue state, an assignee, or any other flag (customer
 * order flags, mine, waiting) it KEEPS the row and the caller schedules a refresh: the server
 * decides, and the next first-page refresh drops or re-orders it.
 */
export function matchesInboxFilters(c: Conversation, f: InboxFilters): { keep: boolean; undecided: boolean } {
    const flags = f.flags ?? [];
    const no = { keep: false, undecided: false };

    if (flags.includes('spam')) {
        if (c.priority !== 'spam') return no;
    } else if (c.priority === 'spam') {
        return no; // hidden everywhere except the spam flag
    }
    if (flags.includes('low_priority') && c.priority !== 'low') return no;
    if ((f.status === 'waiting' || flags.some((flag) => EXCLUDES_LOW.includes(flag))) && c.priority === 'low') return no;

    if (f.platform && c.platform !== f.platform) return no;
    if (f.tag && !(c.tags ?? []).some((t) => t.id === f.tag)) return no;

    switch (f.status) {
        case 'open':
        case 'pending':
        case 'resolved':
            if (c.status !== f.status) return no;
            break;
        case 'closed':
            if (c.status !== 'resolved') return no;
            break;
        case 'bot':
            if (c.handler !== 'bot' || c.status === 'resolved') return no;
            break;
        case 'waiting':
        case 'with_moderator':
            // A resolved row is out of both for sure; the rest needs the server.
            if (c.status === 'resolved') return no;
            break;
    }

    if ((flags.includes('needs_human') || flags.includes('queue_all')) && !c.needs_human) return no;
    if (flags.includes('queue_high') && !(c.needs_human && c.priority_level === 'high')) return no;
    if (flags.includes('queue_senior') && !(c.needs_human && c.queue === 'senior')) return no;
    if (flags.includes('bot') && c.handler !== 'bot') return no;
    if (flags.includes('comment') && c.source !== 'comment') return no;
    if (flags.includes('ad') && c.source !== 'ad') return no;
    if (flags.includes('test') && !c.is_test) return no;

    const undecided =
        f.status === 'waiting' || f.status === 'with_moderator' || !!f.queue || !!f.assignee || flags.some((flag) => !LOCAL_FLAGS.includes(flag));

    return { keep: true, undecided };
}
