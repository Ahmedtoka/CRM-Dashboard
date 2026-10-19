import type { QueueEntry } from '@/types/crm';

/**
 * Which clock a moderator's open window shows (owner 2026-10-19: «عداد للعميلة لوحده وعداد ليا لوحده»):
 *
 * - `overdue`          she waited past the apology — the hand-off countdown.
 * - `awaiting_agent`   she wrote and waits for the moderator: how long she has been waiting.
 * - `awaiting_customer` the moderator answered and waits for her: the silence auto-close countdown,
 *                      after which the chat may be closed.
 * - `chat`             nobody is waiting on anybody yet (the window was just delivered): chat time.
 */
export type WindowClock = 'overdue' | 'awaiting_agent' | 'awaiting_customer' | 'chat';

export function windowClock(entry: Pick<QueueEntry, 'awaiting_reply_since' | 'reply_overdue'>, silenceLeft: number | null): WindowClock {
    if (entry.reply_overdue) return 'overdue';
    if (entry.awaiting_reply_since) return 'awaiting_agent';
    if (silenceLeft !== null) return 'awaiting_customer';

    return 'chat';
}
