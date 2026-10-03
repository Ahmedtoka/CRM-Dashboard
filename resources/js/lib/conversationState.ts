import type { Conversation } from '@/types/crm';

export type StatusChipTone = 'neutral' | 'positive' | 'warning' | 'negative' | 'info' | 'overdue';
export type Translate = (key: string, params?: Record<string, string | number>) => string;

export type RowState = {
    key: 'overdue' | 'lounge' | 'with' | 'needs_human' | 'bot' | 'closed' | 'spam' | 'low' | 'test';
    label: string;
    tone: StatusChipTone;
};

/** Ticket numbers carry the business date in front; people read the last five digits (as the board does). */
export const shortTicket = (ticket: number): number => ticket % 100000;

/** Who she is with: the assignee, else whoever is handling it now, else (a human chat) the last responder. */
function withName(c: Conversation, names: Map<number, string>): string | null {
    if (c.assignee) return c.assignee.name || names.get(c.assignee.id) || null;
    if (c.handling) return c.handling.name || null;
    if (c.handler === 'human' && c.last_responder_id) return names.get(c.last_responder_id) ?? null;

    return null;
}

/**
 * The one state badge a list row shows (spec §1.2), first match wins:
 * test > spam / low > overdue > in the lounge > with [name] > needs a person > bot > closed.
 * A resolved conversation only ever reads «مقفولة» (after test / spam / low): an old ticket or
 * assignee on it is history, not a state.
 */
export function conversationState(c: Conversation, t: Translate, names: Map<number, string>): RowState | null {
    if (c.is_test) return { key: 'test', label: t('inbox.test_badge'), tone: 'neutral' };
    if (c.priority === 'spam') return { key: 'spam', label: t('inbox.filters.spam'), tone: 'neutral' };
    if (c.priority === 'low') return { key: 'low', label: t('inbox.filters.low_priority'), tone: 'neutral' };

    if (c.status === 'resolved') return { key: 'closed', label: t('inbox.state.closed'), tone: 'neutral' };

    const q = c.queue_state ?? null;
    if (q?.overdue) return { key: 'overdue', label: t('inbox.state.overdue'), tone: 'overdue' };
    if (q?.status === 'waiting') return { key: 'lounge', label: t('inbox.state.lounge', { ticket: shortTicket(q.ticket) }), tone: 'info' };

    const name = withName(c, names);
    if (name) return { key: 'with', label: t('inbox.state.with', { name }), tone: 'positive' };
    if (c.needs_human) return { key: 'needs_human', label: t('inbox.state.needs_human'), tone: 'negative' };
    if (c.handler === 'bot') return { key: 'bot', label: t('inbox.state.bot'), tone: 'info' };

    return null;
}
