/**
 * Q10 (close-cycle-FINAL §7.2): before her first reply on a chat the bot holds, the web asks once
 * «هتستلمي المحادثة دي من البوت». Remembered per user and conversation in this browser; with
 * storage blocked it is remembered for this page only, so it still asks just once.
 */
const memory = new Set<string>();

const key = (userId: number, conversationId: number) => `inbox:claim-ok:${userId}:${conversationId}`;

export function claimConfirmed(userId: number, conversationId: number): boolean {
    const k = key(userId, conversationId);
    if (memory.has(k)) return true;
    try {
        return window.localStorage.getItem(k) === '1';
    } catch {
        return false;
    }
}

export function rememberClaim(userId: number, conversationId: number): void {
    const k = key(userId, conversationId);
    memory.add(k);
    try {
        window.localStorage.setItem(k, '1');
    } catch {
        // Kept in memory for this page.
    }
}

/** Tests only. */
export function forgetClaims(): void {
    memory.clear();
}
