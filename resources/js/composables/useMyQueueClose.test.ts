import { closeBody } from '@/composables/useMyQueue';
import { describe, expect, it } from 'vitest';

describe('closeBody', () => {
    it('carries the outcome next to the reason and the case type only for a case', () => {
        expect(closeBody('inquiry', null, { outcome: 'price' })).toEqual({ reason: 'inquiry', outcome: 'price' });
        expect(closeBody('case', 'return', {})).toEqual({ reason: 'case', case_type: 'return' });
        expect(closeBody('problem', 'return', { outcome: 'other', outcome_note: 'x' })).toEqual({ reason: 'problem', outcome: 'other', outcome_note: 'x' });
    });

    it('asks for no closing message only on the way back to the bot (close-cycle hotfix C1, D-03)', () => {
        expect(closeBody('inquiry', null, { outcome: 'price' }, true)).toEqual({ reason: 'inquiry', outcome: 'price', to_bot: true });
        expect(closeBody('inquiry', null, {}, false)).toEqual({ reason: 'inquiry' });
    });
});
