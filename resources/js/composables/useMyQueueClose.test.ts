import { closeBody } from '@/composables/useMyQueue';
import { describe, expect, it } from 'vitest';

describe('closeBody', () => {
    it('carries the outcome next to the reason and the case type only for a case', () => {
        expect(closeBody('inquiry', null, { outcome: 'price' })).toEqual({ reason: 'inquiry', outcome: 'price' });
        expect(closeBody('case', 'return', {})).toEqual({ reason: 'case', case_type: 'return' });
        expect(closeBody('problem', 'return', { outcome: 'other', outcome_note: 'x' })).toEqual({ reason: 'problem', outcome: 'other', outcome_note: 'x' });
    });
});
