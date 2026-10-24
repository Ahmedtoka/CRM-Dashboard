import type { AgentOutcome, OutcomeKey, OutcomePayload, OutcomeSuggestion, SupportCaseType } from '@/types/crm';

/**
 * The «خلصت» outcomes (finish-api.md §1): ONE pick that says what happened, in three groups.
 * The PHP enum (app/Inbox/Outcomes/Outcome.php) is the source of truth; this mirrors its GROUPS.
 */
export type OutcomeGroup = 'sale' | 'service' | 'rest';

export const OUTCOME_GROUPS: { key: OutcomeGroup; outcomes: AgentOutcome[] }[] = [
    { key: 'sale', outcomes: ['ordered', 'price', 'size_out', 'shipping', 'browsing'] },
    { key: 'service', outcomes: ['order_inquiry', 'return_case', 'exchange_case', 'complaint_case', 'order_change'] },
    { key: 'rest', outcomes: ['no_answer', 'spam', 'other'] },
];

export const AGENT_OUTCOMES: AgentOutcome[] = OUTCOME_GROUPS.flatMap((g) => g.outcomes);

/** Digit keys 1-9 pick the first nine outcomes in sheet order (Arabic-Indic digits too). */
const DIGITS = '123456789';
const ARABIC_DIGITS = '١٢٣٤٥٦٧٨٩';

export function outcomeForKey(key: string): AgentOutcome | null {
    const index = DIGITS.indexOf(key) >= 0 ? DIGITS.indexOf(key) : ARABIC_DIGITS.indexOf(key);

    return index === -1 ? null : (AGENT_OUTCOMES[index] ?? null);
}

/** The digit shown beside an outcome (1-based), or null past the ninth. */
export function outcomeDigit(value: AgentOutcome): number | null {
    const index = AGENT_OUTCOMES.indexOf(value);

    return index >= 0 && index < 9 ? index + 1 : null;
}

/** The support case type a case outcome records (finish-api.md §3.2). */
export const OUTCOME_CASE_TYPE: Partial<Record<AgentOutcome, SupportCaseType>> = {
    return_case: 'return',
    exchange_case: 'exchange',
    complaint_case: 'complaint',
    order_change: 'cancel_edit',
};

export function isCaseOutcome(value: OutcomeKey | null): value is 'return_case' | 'exchange_case' | 'complaint_case' | 'order_change' {
    return value !== null && value in OUTCOME_CASE_TYPE;
}

/** Outcomes that send no thanks and no rating by default: she never replied, or it is spam. */
export function sendsClosing(value: OutcomeKey | null): boolean {
    return value !== 'no_answer' && value !== 'spam';
}

/** Spam goes straight back to the bot: no thanks, no return window. */
export function endsWithBot(value: OutcomeKey | null): boolean {
    return value === 'spam';
}

/** The close may go: a pick («حاجة تانية» with its note), or nothing picked while the server records an automatic one. */
export function outcomeReady(picked: AgentOutcome | null, note: string, auto: OutcomeKey | null): boolean {
    if (picked === null) return auto !== null && auto !== 'unknown';

    return picked !== 'other' || note.trim() !== '';
}

/** What the close request carries: nothing when nothing was picked (the server records the automatic outcome alone). */
export function outcomePayload(picked: AgentOutcome | null, note: string, _auto: OutcomeKey | null): OutcomePayload {
    if (picked === null) return {};

    return picked === 'other' ? { outcome: 'other', outcome_note: note.trim() } : { outcome: picked };
}

/** The outcome the sheet preselects: the server's suggestion, else an automatic one it still knows. */
export function preselectedOutcome(suggested: OutcomeSuggestion | null | undefined, auto: OutcomeKey | null): AgentOutcome | null {
    if (suggested) return suggested.outcome;
    if (auto !== null && auto !== 'unknown' && auto !== 'service') return auto;
    if (auto === 'service') return 'order_inquiry';

    return null;
}
