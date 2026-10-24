import {
    AGENT_OUTCOMES,
    OUTCOME_GROUPS,
    endsWithBot,
    isCaseOutcome,
    outcomeDigit,
    outcomeForKey,
    outcomePayload,
    outcomeReady,
    preselectedOutcome,
    sendsClosing,
} from '@/lib/outcomes';
import { describe, expect, it } from 'vitest';

describe('outcomes', () => {
    it('has the three groups in the sheet order, mirroring the PHP enum', () => {
        expect(OUTCOME_GROUPS.map((g) => g.key)).toEqual(['sale', 'service', 'rest']);
        expect(AGENT_OUTCOMES).toEqual([
            'ordered',
            'price',
            'size_out',
            'shipping',
            'browsing',
            'order_inquiry',
            'return_case',
            'exchange_case',
            'complaint_case',
            'order_change',
            'no_answer',
            'spam',
            'other',
        ]);
    });

    it('maps 1-9 and Arabic-Indic digits to the first nine outcomes in order', () => {
        expect(['1', '2', '3', '4', '5', '6', '7', '8', '9'].map(outcomeForKey)).toEqual(AGENT_OUTCOMES.slice(0, 9));
        expect(['١', '٢', '٣'].map(outcomeForKey)).toEqual(['ordered', 'price', 'size_out']);
        expect(outcomeForKey('0')).toBeNull();
        expect(outcomeForKey('a')).toBeNull();
        expect(outcomeDigit('ordered')).toBe(1);
        expect(outcomeDigit('complaint_case')).toBe(9);
        expect(outcomeDigit('spam')).toBeNull();
    });

    it('is ready with a pick, with a known automatic outcome, and «حاجة تانية» needs a note', () => {
        expect(outcomeReady(null, '', null)).toBe(false);
        expect(outcomeReady(null, '', 'unknown')).toBe(false);
        expect(outcomeReady('price', '', null)).toBe(true);
        expect(outcomeReady(null, '', 'ordered')).toBe(true);
        expect(outcomeReady(null, '', 'return_case')).toBe(true);
        expect(outcomeReady('other', '  ', null)).toBe(false);
        expect(outcomeReady('other', 'تقسيط', null)).toBe(true);
    });

    it('sends the pick (the note only for «حاجة تانية»), nothing when nothing was picked', () => {
        expect(outcomePayload('price', '', 'ordered')).toEqual({ outcome: 'price' });
        expect(outcomePayload(null, '', 'ordered')).toEqual({});
        expect(outcomePayload('shipping', 'x', null)).toEqual({ outcome: 'shipping' });
        expect(outcomePayload('other', ' تقسيط ', null)).toEqual({ outcome: 'other', outcome_note: 'تقسيط' });
    });

    it('knows which outcomes record a case, send no closing message, or go straight to the bot', () => {
        expect(['return_case', 'exchange_case', 'complaint_case', 'order_change'].every((o) => isCaseOutcome(o as never))).toBe(true);
        expect(isCaseOutcome('order_inquiry')).toBe(false);
        expect(isCaseOutcome(null)).toBe(false);
        expect(sendsClosing('no_answer')).toBe(false);
        expect(sendsClosing('spam')).toBe(false);
        expect(sendsClosing('return_case')).toBe(true);
        expect(sendsClosing(null)).toBe(true);
        expect(endsWithBot('spam')).toBe(true);
        expect(endsWithBot('no_answer')).toBe(false);
    });

    it('preselects the suggestion, else an automatic outcome (the legacy service as an order inquiry)', () => {
        const s = { outcome: 'no_answer', why: 'no_reply', params: {}, case_id: null, order_id: null } as const;
        expect(preselectedOutcome(s, 'ordered')).toBe('no_answer');
        expect(preselectedOutcome(null, 'ordered')).toBe('ordered');
        expect(preselectedOutcome(null, 'service')).toBe('order_inquiry');
        expect(preselectedOutcome(null, 'unknown')).toBeNull();
        expect(preselectedOutcome(undefined, null)).toBeNull();
    });
});
