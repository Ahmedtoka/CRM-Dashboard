import { windowClock } from '@/lib/windowClock';
import { describe, expect, it } from 'vitest';

// Owner 2026-10-19: one clock for her wait (for the moderator) and one for ours (for her reply, to the auto-close).
describe('windowClock', () => {
    it('shows her wait while she waits for the moderator, whatever the silence clock says', () => {
        expect(windowClock({ awaiting_reply_since: '2026-10-19T15:02:10+00:00', reply_overdue: false }, null)).toBe('awaiting_agent');
        expect(windowClock({ awaiting_reply_since: '2026-10-19T15:02:10+00:00', reply_overdue: false }, 300)).toBe('awaiting_agent');
    });

    it('shows the auto-close countdown once the moderator answered and waits for her', () => {
        expect(windowClock({ awaiting_reply_since: null, reply_overdue: false }, 420)).toBe('awaiting_customer');
    });

    it('shows the hand-off once she waited past the apology', () => {
        expect(windowClock({ awaiting_reply_since: '2026-10-19T15:02:10+00:00', reply_overdue: true }, null)).toBe('overdue');
    });

    it('falls back to the chat time when nobody waits on anybody yet', () => {
        expect(windowClock({ awaiting_reply_since: null, reply_overdue: false }, null)).toBe('chat');
    });
});
