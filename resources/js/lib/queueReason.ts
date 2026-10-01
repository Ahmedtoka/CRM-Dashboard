import type { Translate } from '@/lib/conversationState';

/** Reasons that all mean «she asked for a person», whatever step of the bot raised them. */
const ASKED_HUMAN = new Set(['asked_human', 'human_request', 'keyword']);

/** Never shown: they say nothing a moderator can act on. */
const HIDDEN = new Set(['unknown', 'summary', 'data', 'text', 'reason']);

/**
 * The bot-summary `reason` of a queue ticket as words a moderator reads, or null when there is
 * nothing worth showing. A raw key (`asked_human`, `ai_low_confidence` …) is never returned:
 * an unknown reason is hidden.
 *
 *     queueReason('asked_human', t) // «طلبت موظفة»
 *     queueReason('no_rule', t)     // «لا توجد قاعدة مطابقة» (the bot report's label)
 *     queueReason('x_new_key', t)   // null
 */
export function queueReason(reason: unknown, t: Translate): string | null {
    if (typeof reason !== 'string') return null;
    const key = reason.trim();
    if (!/^[a-z_]+$/.test(key) || HIDDEN.has(key)) return null;
    if (ASKED_HUMAN.has(key)) return t('queue.reason.asked_human');

    for (const path of [`queue.reason.${key}`, `reports.bot.reasons.${key}`]) {
        const label = t(path);
        if (label !== path) return label;
    }

    return null;
}
