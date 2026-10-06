import { mergeMember } from '@/lib/board/state';
import type { BoardMember } from '@/types/board';
import { describe, expect, it } from 'vitest';

describe('mergeMember', () => {
    it('keeps the board-state-only rating across a QueueMemberUpdated broadcast', () => {
        const before = { id: 1, shift_id: 3, status: 'busy', rating: { count: 2, avg: 3.5, low: 1 }, platforms: ['facebook'] } as unknown as BoardMember;
        const payload = { id: 1, shift_id: 3, status: 'available' } as unknown as BoardMember;

        const next = mergeMember([before], payload, 3);

        expect(next?.[0].status).toBe('available');
        expect(next?.[0].rating).toEqual({ count: 2, avg: 3.5, low: 1 });
    });
});
