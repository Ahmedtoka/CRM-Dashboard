import { enterConfirms, finishBody, finishTarget, type FinishChoices } from '@/lib/finish';
import type { Conversation } from '@/types/crm';
import { describe, expect, it } from 'vitest';

const conv = (over: Partial<Conversation> = {}): Conversation =>
    ({
        id: 9,
        status: 'open',
        handler: 'bot',
        queue_entry: null,
        queue_state: null,
        open_case_id: null,
        can: { reply: true },
        ...over,
    }) as Conversation;

const windowEntry = (assigned: number) =>
    ({ id: 41, assigned_user_id: assigned, ticket: 100008, window_no: 8, open_case_id: 5 }) as unknown as Conversation['queue_entry'];

describe('finishTarget (the header primary action)', () => {
    it('her own open window, and a supervisor / admin on anyone’s', () => {
        expect(finishTarget(conv({ queue_entry: windowEntry(3) }), 3, 'moderator')).toMatchObject({
            kind: 'window',
            entryId: 41,
            windowNo: 8,
            openCaseId: 5,
        });
        expect(finishTarget(conv({ queue_entry: windowEntry(7) }), 3, 'supervisor').kind).toBe('window');
        expect(finishTarget(conv({ queue_entry: windowEntry(7) }), 3, 'admin').kind).toBe('window');
    });

    it('somebody else’s window is held (the lock chip)', () => {
        expect(finishTarget(conv({ queue_entry: windowEntry(7) }), 3, 'moderator').kind).toBe('held');
    });

    it('a customer waiting in the lounge names her ticket when the payload has it', () => {
        expect(
            finishTarget(
                conv({ queue_state: { id: 77, status: 'waiting', ticket: 12, priority: 'live', overdue: false, assigned_user_id: null } }),
                3,
                'moderator',
            ),
        ).toEqual({
            kind: 'lounge',
            entryId: 77,
        });
        expect(
            finishTarget(
                conv({ queue_state: { status: 'waiting', ticket: 12, priority: 'live', overdue: false, assigned_user_id: null } }),
                3,
                'moderator',
            ),
        ).toEqual({
            kind: 'lounge',
            entryId: null,
        });
    });

    it('a human chat with no window (queue off too); nothing for the bot or a resolved chat', () => {
        expect(finishTarget(conv({ handler: 'human' }), 3, 'moderator').kind).toBe('none');
        expect(finishTarget(conv({ handler: 'bot' }), 3, 'moderator').kind).toBe('idle');
        expect(finishTarget(conv({ handler: 'human', status: 'resolved' }), 3, 'moderator').kind).toBe('idle');
        expect(finishTarget(conv({ handler: 'human', can: { reply: false } }), 3, 'moderator').kind).toBe('idle');
    });
});

const base: FinishChoices = {
    path: 'window',
    entryId: 41,
    picked: 'price',
    note: '',
    auto: null,
    after: 'thanks',
    sendClosing: true,
    waiting: false,
    resolveCaseId: null,
};

describe('finishBody', () => {
    it('a window: entry, outcome, thanks with the closing message', () => {
        expect(finishBody(base)).toEqual({ entry_id: 41, outcome: 'price', after: 'thanks', send_closing: true });
    });

    it('confirms a waiting customer only with thanks; the bot path never sends the closing flag', () => {
        expect(finishBody({ ...base, waiting: true, sendClosing: false })).toEqual({
            entry_id: 41,
            outcome: 'price',
            after: 'thanks',
            send_closing: false,
            confirm_waiting: true,
        });
        expect(finishBody({ ...base, waiting: true, after: 'bot', picked: 'browsing' })).toEqual({ entry_id: 41, outcome: 'browsing', after: 'bot' });
    });

    it('the lounge sends the outcome only; no window sends entry_id null and no case fields', () => {
        expect(finishBody({ ...base, path: 'lounge', entryId: 77, picked: null })).toEqual({ entry_id: 77 });
        expect(finishBody({ ...base, path: 'none', entryId: null, resolveCaseId: 3 })).toEqual({
            entry_id: null,
            outcome: 'price',
            after: 'thanks',
            send_closing: true,
        });
    });

    it('nothing picked sends no outcome (the server records the automatic one); a case is resolved, never opened from here', () => {
        expect(finishBody({ ...base, auto: 'ordered', picked: null })).toEqual({ entry_id: 41, after: 'thanks', send_closing: true });
        expect(finishBody({ ...base, resolveCaseId: 5 })).toMatchObject({ resolve_case_id: 5 });
        expect(finishBody({ ...base, picked: 'return_case' })).not.toHaveProperty('open_case');
        expect(finishBody({ ...base, picked: 'other', note: '  later ' })).toMatchObject({ outcome: 'other', outcome_note: 'later' });
    });

    it('carries the suggestion the sheet showed, in the lounge too', () => {
        expect(finishBody({ ...base, suggested: 'no_answer' })).toEqual({
            entry_id: 41,
            outcome: 'price',
            suggested_outcome: 'no_answer',
            after: 'thanks',
            send_closing: true,
        });
        expect(finishBody({ ...base, path: 'lounge', entryId: 77, suggested: 'no_answer' })).toMatchObject({
            entry_id: 77,
            suggested_outcome: 'no_answer',
        });
        expect(finishBody({ ...base, suggested: null })).not.toHaveProperty('suggested_outcome');
    });
});

describe('enterConfirms', () => {
    it('not while typing, not on a switch or the cancel buttons', () => {
        const input = document.createElement('input');
        const sw = document.createElement('button');
        sw.setAttribute('role', 'switch');
        const cancel = document.createElement('button');
        cancel.setAttribute('data-finish-no-enter', '');
        expect(enterConfirms(input)).toBe(false);
        expect(enterConfirms(document.createElement('textarea'))).toBe(false);
        expect(enterConfirms(sw)).toBe(false);
        expect(enterConfirms(cancel)).toBe(false);
        expect(enterConfirms(document.createElement('div'))).toBe(true);
        expect(enterConfirms(document.createElement('button'))).toBe(true);
    });
});
