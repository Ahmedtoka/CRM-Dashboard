/** Queue Part 2: the points ledger, a moderator's day and the queue report. */

export type PointsKind = 'base' | 'speed' | 'case' | 'review' | 'qa' | 'no_reply';

export interface PointsLine {
    id: number;
    business_date: string;
    kind: PointsKind;
    points: number;
    requested: number;
    reason: string;
    queue_entry_id: number | null;
    conversation_id: number | null;
    support_case_id: number | null;
    reversed: boolean;
    is_reversal: boolean;
    at: string | null;
}

export interface PointsSummary {
    total: number;
    cap: number;
    capped: number;
    by_kind: Record<PointsKind, number>;
}

export interface QaChecklist {
    introduced?: boolean;
    accurate?: boolean;
    tone?: boolean;
    right_reason?: boolean;
    followed_up?: boolean;
}

export const QA_ITEMS = ['introduced', 'accurate', 'tone', 'right_reason', 'followed_up'] as const;

export interface DayWindow {
    id: number;
    ticket: number;
    conversation_id: number;
    customer: string | null;
    close_reason: string | null;
    closed_at: string | null;
    handle_seconds: number | null;
    first_reply_seconds: number | null;
    review_stars: number | null;
    qa: { score: number; checklist: QaChecklist; notes: string | null; reviewer: { id: number; name: string } | null } | null;
    can_qa: boolean;
}

export interface QueueDay {
    user: { id: number; name: string; color: string | null };
    date: string;
    points: PointsSummary & { ledger: PointsLine[] };
    windows: DayWindow[];
}

export interface QueueReportRow {
    user: { id: number; name: string; color: string | null };
    received: number;
    closed: Record<string, number>;
    handled: number;
    first_reply_avg_seconds: number | null;
    handle_avg_seconds: number | null;
    sla_pct: number | null;
    rating_avg: number | null;
    rating_count: number;
    qa_avg: number | null;
    qa_count: number;
    cases_opened: number;
    cases_resolved: number;
    points: Record<PointsKind, number>;
    total: number;
}

export interface QueueReportKpis {
    tickets: number;
    windows: number;
    handled: number;
    closed: Record<string, number>;
    first_reply_avg_seconds: number | null;
    handle_avg_seconds: number | null;
    sla_pct: number | null;
    rating_avg: number | null;
    rating_count: number;
    cases_opened: number;
    cases_resolved: number;
    cases_resolved_in_time: number;
    cases_overdue: number;
    points_total: number;
}

export interface QueueReport {
    from: string;
    to: string;
    kpis: QueueReportKpis;
    rows: QueueReportRow[];
}
