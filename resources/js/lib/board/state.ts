import { SEATS } from '@/lib/board/layout';
import type { BoardMember } from '@/types/board';
import type { QueueEntry, QueuePriority } from '@/types/crm';

/** Pure steps of the live board's state: no network, no timers, no DOM beyond one media query. */

const OPEN = ['called', 'active'];

/** The router's order in the lounge: returning, escalations, live and manual, then the night's backlog. */
const RANK: Record<QueuePriority, number> = { returning: 0, escalation: 1, live: 2, manual: 2, overnight: 3 };

function byLoungeOrder(a: QueueEntry, b: QueueEntry): number {
    return (
        (RANK[a.priority] ?? 2) - (RANK[b.priority] ?? 2) ||
        (Date.parse(a.enqueued_at ?? '') || 0) - (Date.parse(b.enqueued_at ?? '') || 0) ||
        a.id - b.id
    );
}

function byWindow(a: QueueEntry, b: QueueEntry): number {
    return (a.window_no ?? 99) - (b.window_no ?? 99) || a.id - b.id;
}

/** One `QueueEntryUpdated` applied to the lounge and the windows. */
export function applyEntry(waiting: QueueEntry[], open: QueueEntry[], entry: QueueEntry): { waiting: QueueEntry[]; open: QueueEntry[] } {
    const nextWaiting = waiting.filter((e) => e.id !== entry.id);
    const nextOpen = open.filter((e) => e.id !== entry.id);

    if (entry.status === 'waiting') nextWaiting.push(entry);
    else if (OPEN.includes(entry.status)) nextOpen.push(entry);

    return { waiting: nextWaiting.sort(byLoungeOrder), open: nextOpen.sort(byWindow) };
}

export interface EntryChange {
    /** Customers who were given a window, with the lounge seat each one leaves (-1: not seated). */
    called: { entry: QueueEntry; seat: number }[];
    /** Windows that became free, as `userId:windowNo`. */
    freed: string[];
}

export function windowKey(userId: number | null, windowNo: number | null): string {
    return `${userId ?? 0}:${windowNo ?? 0}`;
}

/** What moved between two states of the room. */
export function diffEntries(waitingBefore: QueueEntry[], openBefore: QueueEntry[], openAfter: QueueEntry[]): EntryChange {
    const seats = new Map(waitingBefore.map((e, i) => [e.id, i < SEATS ? i : -1]));
    const before = new Map(openBefore.map((e) => [e.id, e]));
    const after = new Set(openAfter.map((e) => e.id));
    const taken = new Set(openAfter.map((e) => windowKey(e.assigned_user_id, e.window_no)));

    return {
        called: openAfter.filter((e) => !before.has(e.id)).map((entry) => ({ entry, seat: seats.get(entry.id) ?? -1 })),
        freed: [...before.values()]
            .filter((e) => !after.has(e.id))
            .map((e) => windowKey(e.assigned_user_id, e.window_no))
            .filter((key) => !taken.has(key)),
    };
}

/**
 * One `QueueMemberUpdated` applied to the desks. The broadcast does not carry what only the
 * state knows (platforms, leader, attendance), so those are kept. Null when the desk is not known
 * here (she just joined, or it is a desk of another shift): the state must be read again.
 */
export function mergeMember(members: BoardMember[], payload: BoardMember, openShiftId: number | null): BoardMember[] | null {
    const known = members.some((m) => m.id === payload.id);

    if (payload.status === 'left') return known ? members.filter((m) => m.id !== payload.id) : members;
    if (!known) return payload.shift_id === openShiftId ? null : members;

    return members.map((m) => (m.id === payload.id ? { ...m, ...payload, is_leader: m.is_leader, platforms: m.platforms, attendance: m.attendance, rating: m.rating } : m));
}

/** On the roster and serving, but her moderator is not logged in: «مش فاتحة» (the router skips her). */
export function notOnline(member: Pick<BoardMember, 'status' | 'online'>): boolean {
    return (member.status === 'available' || member.status === 'busy') && member.online === false;
}

/** The windows of a desk by number: `count` places, each her customer or free. */
export function windowSlots(entries: QueueEntry[], cap: number): (QueueEntry | null)[] {
    const count = Math.max(cap, entries.length, 1);
    const slots: (QueueEntry | null)[] = Array.from({ length: count }, () => null);
    const loose: QueueEntry[] = [];

    for (const entry of [...entries].sort(byWindow)) {
        const at = (entry.window_no ?? 0) - 1;
        if (at >= 0 && at < count && slots[at] === null) slots[at] = entry;
        else loose.push(entry);
    }
    for (const entry of loose) {
        const free = slots.indexOf(null);
        if (free >= 0) slots[free] = entry;
    }

    return slots;
}

export function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** The colour of a customer's outfit, steady per customer, from the simulator's palette. */
const OUTFITS = ['#c2185b', '#7b1fa2', '#00838f', '#ad1457', '#4527a0', '#2e7d32', '#ef6c00', '#6d4c41', '#1565c0', '#9e9d24', '#d81b60', '#00695c'];

export function outfitOf(entry: Pick<QueueEntry, 'id' | 'customer'>): { color: string; alt: boolean } {
    const seed = entry.customer?.id ?? entry.id;

    return { color: OUTFITS[seed % OUTFITS.length], alt: seed % 3 === 0 };
}

/** The colour of a moderator's outfit: her own colour, else a soft one from the simulator's team. */
const TEAM = ['#8fd3ff', '#ffd166', '#f7a1c4', '#9be7c4', '#c9b6ff', '#ffb38a', '#a5f3c9', '#ffc9de'];

export function teamColour(user: { id: number; color?: string | null } | null | undefined): string {
    if (user?.color && /^#[0-9a-f]{3,8}$/i.test(user.color)) return user.color;

    return TEAM[(user?.id ?? 0) % TEAM.length];
}

const PLATFORM_VARS: Record<string, string> = { facebook: 'fb', instagram: 'ig', whatsapp: 'wa', tiktok: 'tt' };

/** The room's own colour variable of a platform (`--fb`, `--ig`, `--wa`, `--tt`). */
export function platformVar(platform: string | null | undefined): string {
    return PLATFORM_VARS[platform ?? ''] ?? 'fb';
}
