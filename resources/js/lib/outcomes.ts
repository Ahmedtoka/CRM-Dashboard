import type { AgentOutcome, OutcomeKey, OutcomePayload } from '@/types/crm';

/** The close menu's first row (C 3.1): digit keys 1-4 pick these. */
export const PRIMARY_OUTCOMES: AgentOutcome[] = ['price', 'size_out', 'shipping', 'browsing'];
export const MORE_OUTCOMES: AgentOutcome[] = ['no_answer', 'service', 'other'];

const DIGITS: Record<string, number> = { '1': 0, '2': 1, '3': 2, '4': 3, '١': 0, '٢': 1, '٣': 2, '٤': 3 };

export function outcomeForKey(key: string): AgentOutcome | null {
    const index = DIGITS[key];

    return index === undefined ? null : PRIMARY_OUTCOMES[index];
}

/** The close may go: her pick (other with a note), or an automatic ordered / service (D13). */
export function outcomeReady(picked: AgentOutcome | null, note: string, auto: OutcomeKey | null): boolean {
    if (auto === 'ordered') return true;
    if (picked === null) return auto === 'service';

    return picked !== 'other' || note.trim() !== '';
}

/** What the close request carries: nothing when the server records it alone (ordered, untouched service). */
export function outcomePayload(picked: AgentOutcome | null, note: string, auto: OutcomeKey | null): OutcomePayload {
    if (auto === 'ordered' || picked === null) return {};

    return picked === 'other' ? { outcome: 'other', outcome_note: note.trim() } : { outcome: picked };
}
