import type { Conversation } from '@/types/crm';

/**
 * How long her last message waits for our reply (C 2.2 #3, G7); late after the first-reply target.
 * Null when nothing waits on a person: resolved, or the bot is handling her (not `needs_human`).
 */
export function waitingAge(
    c: Pick<Conversation, 'waiting_since' | 'status' | 'needs_human'>,
    now: number,
    targetSeconds: number | null,
): { seconds: number; late: boolean } | null {
    if (!c.waiting_since || c.status === 'resolved' || !c.needs_human) return null;
    const since = Date.parse(c.waiting_since);
    if (Number.isNaN(since)) return null;
    const seconds = Math.max(0, Math.floor((now - since) / 1000));

    return { seconds, late: targetSeconds !== null && seconds >= targetSeconds };
}
