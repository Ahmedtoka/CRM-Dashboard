import { outcomePayload } from '@/lib/outcomes';
import type { AgentOutcome, Conversation, FinishAfter, FinishBody, FinishPath, OutcomeKey } from '@/types/crm';

/** «تراجع» shows in the toast this long; the server allows 15 s (close-cycle-FINAL §4.5). */
export const UNDO_TOAST_MS = 10_000;

/** What «خلصت» would end on this chat for this user, or why there is no «خلصت». */
export type FinishTarget =
    | { kind: 'window'; entryId: number; windowNo: number | null; ticket: number; assignedUserId: number | null; openCaseId: number | null }
    | { kind: 'lounge'; entryId: number | null }
    | { kind: 'none' }
    /** Somebody else's open window: the lock chip, no «خلصت». */
    | { kind: 'held' }
    /** The bot holds it, or it is resolved already: nothing to finish. */
    | { kind: 'idle' };

/**
 * The header's one primary action (close-cycle-FINAL §0, §8): her open window (or a supervisor /
 * admin on anyone's), a customer waiting in the lounge, a human chat with no window (also with
 * the queue off). Open windows show even with the queue switched off (§8, M-05).
 */
export function finishTarget(conversation: Conversation, meId: number, role: string | null | undefined): FinishTarget {
    const entry = conversation.queue_entry;
    if (entry) {
        const mayEnd = entry.assigned_user_id === meId || role === 'supervisor' || role === 'admin';

        return mayEnd
            ? {
                  kind: 'window',
                  entryId: entry.id,
                  windowNo: entry.window_no,
                  ticket: entry.ticket,
                  assignedUserId: entry.assigned_user_id,
                  openCaseId: entry.open_case_id,
              }
            : { kind: 'held' };
    }
    if (!conversation.can.reply) return { kind: 'idle' };
    if (conversation.queue_state?.status === 'waiting') return { kind: 'lounge', entryId: conversation.queue_state.id ?? null };
    if (conversation.handler === 'human' && conversation.status !== 'resolved') return { kind: 'none' };

    return { kind: 'idle' };
}

export function isFinishable(target: FinishTarget): target is Extract<FinishTarget, { kind: FinishPath }> {
    return target.kind === 'window' || target.kind === 'lounge' || target.kind === 'none';
}

export interface FinishChoices {
    path: FinishPath;
    entryId: number | null;
    picked: AgentOutcome | null;
    note: string;
    auto: OutcomeKey | null;
    after: FinishAfter;
    sendClosing: boolean;
    waiting: boolean;
    resolveCaseId: number | null;
    /** The outcome the sheet showed preselected (recorded with whether she followed it); null = none was shown. */
    suggested?: AgentOutcome | null;
}

/**
 * The POST body for these choices (finish-api.md §3). `entry_id` is always present (RC-1). The lounge
 * sends only the outcome (the server cancels the ticket and tells her, §4.3); «البوت يكمّل» sends no
 * closing flag and never confirms a wait (the bot answers her, RC-17). A case outcome never sends
 * `open_case`: the server links her open case or opens the one the outcome records.
 */
export function finishBody(c: FinishChoices): FinishBody {
    const body: FinishBody = { entry_id: c.entryId, ...outcomePayload(c.picked, c.note, c.auto) };
    if (c.suggested) body.suggested_outcome = c.suggested;
    if (c.path === 'lounge') return body;

    body.after = c.after;
    if (c.after === 'thanks') {
        body.send_closing = c.sendClosing;
        if (c.waiting) body.confirm_waiting = true;
    }
    if (c.path === 'window' && c.resolveCaseId !== null) body.resolve_case_id = c.resolveCaseId;

    return body;
}

/** Digits pick an outcome only outside text fields; Enter confirms only outside fields and toggles (a switch, a checkbox). */
export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    if (target.isContentEditable || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement) return true;

    return target instanceof HTMLInputElement;
}

export function enterConfirms(target: EventTarget | null): boolean {
    if (isTypingTarget(target)) return false;
    if (!(target instanceof HTMLElement)) return true;

    return !target.closest('[role="switch"], [data-finish-no-enter]');
}
