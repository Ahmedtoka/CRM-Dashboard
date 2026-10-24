import type { OutcomeKey } from '@/types/crm';

/* Reports › الفريق › «نتائج المحادثات» (2026-10-24): the payload of /reports/outcomes (OutcomesReport::build). */

export type OutcomesGroup = 'day' | 'week' | 'month';

export interface OutcomesFigures {
    total: number;
    /** Everything but spam and the unrecorded: the base of every share. */
    counted: number;
    by_outcome: Record<OutcomeKey, number>;
    purchases: number;
    lost: number;
    returns: number;
    complaints: number;
    complaints_pending: number;
    no_reply: number;
    no_reply_pct: number | null;
    purchase_pct: number | null;
    avg_close_seconds: number | null;
    suggested: number;
    followed: number;
    followed_pct: number | null;
    automatic: number;
    unknown: number;
}

export interface OutcomesPeriod extends OutcomesFigures {
    /** The day, the week's Saturday, or `YYYY-MM`. */
    key: string;
    from: string;
}

export interface OutcomesRow extends OutcomesFigures {
    /** Null: the automatic ends (the bot, the idle sweep) nobody picked. */
    user: { id: number; name: string; color: string | null } | null;
}

export interface OutcomesReport {
    from: string;
    to: string;
    group: OutcomesGroup;
    user_id: number | null;
    kpis: OutcomesFigures;
    by_outcome: Record<OutcomeKey, number>;
    periods: OutcomesPeriod[];
    rows: OutcomesRow[];
}
