import { outcomeForKey, outcomePayload, outcomeReady } from '@/lib/outcomes';
import { describe, expect, it } from 'vitest';

describe('outcomes', () => {
    it('maps 1-4 and Arabic-Indic digits to the four primary outcomes', () => {
        expect(['1', '2', '3', '4'].map(outcomeForKey)).toEqual(['price', 'size_out', 'shipping', 'browsing']);
        expect(['١', '٢', '٣', '٤'].map(outcomeForKey)).toEqual(['price', 'size_out', 'shipping', 'browsing']);
        expect(outcomeForKey('5')).toBeNull();
        expect(outcomeForKey('a')).toBeNull();
    });

    it('is ready with a pick, with an automatic ordered or service, and other needs a note', () => {
        expect(outcomeReady(null, '', null)).toBe(false);
        expect(outcomeReady('price', '', null)).toBe(true);
        expect(outcomeReady(null, '', 'ordered')).toBe(true);
        expect(outcomeReady(null, '', 'service')).toBe(true);
        expect(outcomeReady('other', '  ', null)).toBe(false);
        expect(outcomeReady('other', 'تقسيط', null)).toBe(true);
    });

    it('sends nothing for ordered, the pick otherwise, and the trimmed note only for other', () => {
        expect(outcomePayload('price', '', 'ordered')).toEqual({});
        expect(outcomePayload(null, '', 'service')).toEqual({});
        expect(outcomePayload('shipping', 'x', null)).toEqual({ outcome: 'shipping' });
        expect(outcomePayload('other', ' تقسيط ', null)).toEqual({ outcome: 'other', outcome_note: 'تقسيط' });
    });
});
