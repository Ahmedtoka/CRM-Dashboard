import type { PlatformValue, QueueEntry, Role, ShiftMember, UserRef } from '@/types/crm';

/** One open window as the desk carries it (ShiftMemberResource `windows`). */
export interface BoardWindowRef {
    entry_id: number;
    ticket: number;
    window_no: number | null;
    kind: string;
    platform: PlatformValue | null;
    delivered_at: string | null;
    silence_left_seconds: number | null;
}

/**
 * A desk of the open shift. The last two fields come with the board state only; the
 * `QueueMemberUpdated` broadcast does not carry them, so they are kept from the state.
 */
export interface BoardMember extends ShiftMember {
    windows: BoardWindowRef[];
    is_leader?: boolean;
    platforms?: PlatformValue[];
}

export interface BoardShift {
    id: number;
    date: string | null;
    shift_key: string;
    name: string;
    status: 'planned' | 'open' | 'closed';
    starts_at: string | null;
    ends_at: string | null;
    opened_at: string | null;
    leader: UserRef | null;
}

export interface BoardShiftRow extends BoardShift {
    member_user_ids: number[];
}

export interface BoardDecision {
    id: number;
    trigger: string;
    lines: string[];
    at: string | null;
}

export interface BoardCall {
    entry_id: number;
    ticket: number;
    window_no: number | null;
    user_id: number | null;
    name: string | null;
    at: string | null;
}

export interface BoardKpis {
    issued: number;
    waiting: number;
    waiting_overnight: number;
    escalations_waiting: number;
    longest_wait_seconds: number | null;
    open: number;
    capacity: number;
    sla_replied: number;
    sla_met: number;
    sla_pct: number | null;
    sla_target_pct: number;
    closed: Record<string, number>;
    closed_manual: number;
    closed_total: number;
}

export interface BoardSettings {
    windows_per_moderator: number;
    silence_warn_seconds: number;
    silence_close_seconds: number;
    sla_first_reply_seconds: number;
    sla_target_pct: number;
    break_minutes: number;
}

export interface BoardTemplate {
    key: string;
    name: string;
    from: string;
    to: string;
    location: string;
    leader_user_id: number | null;
    starts_at: string;
    ends_at: string;
    /** What became of it today; null while today's shift does not exist yet. */
    status: 'planned' | 'open' | 'closed' | null;
    shift_id: number | null;
    /** The one covering now, else the next ahead. */
    opens_now: boolean;
}

export interface BoardUser {
    id: number;
    name: string;
    role: Role | null;
    color: string | null;
    platforms: PlatformValue[];
    online: boolean;
}

/** GET /board/state, and the answer of every board action. */
export interface BoardSnapshot {
    enabled: boolean;
    now: string;
    business_date?: string;
    shift?: BoardShift | null;
    shifts?: BoardShiftRow[];
    members?: BoardMember[];
    waiting?: QueueEntry[];
    open?: QueueEntry[];
    decisions?: BoardDecision[];
    last_call?: BoardCall | null;
    kpis?: BoardKpis;
    reception?: { with_bot: number };
    settings?: BoardSettings;
    templates?: BoardTemplate[];
    users?: BoardUser[];
    /** No shift open, but its hours came: the next tick opens it (attendance §2). */
    shift_opening?: boolean;
    /** When the next shift starts; null while one is open. */
    next_shift_starts_at?: string | null;
}

/** A customer walking from her lounge seat to the window she was called to. */
export interface BoardMove {
    id: number;
    entryId: number;
    ticket: number;
    /** Her place in the lounge before she was called; -1 when she was not seated (beyond the seats). */
    seat: number;
    userId: number;
    windowNo: number | null;
}

export type BoardSelection = { kind: 'entry'; id: number } | { kind: 'member'; id: number } | { kind: 'roster' } | null;
