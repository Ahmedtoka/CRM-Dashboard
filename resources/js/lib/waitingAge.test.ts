import { formatAge } from '@/lib/format';
import { waitingAge } from '@/lib/waitingAge';
import { describe, expect, it } from 'vitest';

const now = Date.parse('2026-10-08T10:07:00Z');

describe('waitingAge', () => {
    it('is null when nothing waits or the chat is resolved', () => {
        expect(waitingAge({ waiting_since: null, status: 'open' }, now, 300)).toBeNull();
        expect(waitingAge({ waiting_since: '2026-10-08T10:00:00Z', status: 'resolved' }, now, 300)).toBeNull();
    });

    it('is late after the first-reply target', () => {
        expect(waitingAge({ waiting_since: '2026-10-08T10:00:00Z', status: 'open' }, now, 300)).toEqual({ seconds: 420, late: true });
        expect(waitingAge({ waiting_since: '2026-10-08T10:05:00Z', status: 'open' }, now, 300)).toEqual({ seconds: 120, late: false });
        expect(waitingAge({ waiting_since: '2026-10-08T10:00:00Z', status: 'open' }, now, null)).toEqual({ seconds: 420, late: false });
    });

    it('formats the age as whole minutes or hours', () => {
        expect(formatAge(420, 'ar')).toBe('٧ د');
        expect(formatAge(7200, 'en')).not.toContain('s');
    });
});
