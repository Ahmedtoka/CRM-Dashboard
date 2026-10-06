// «النهارده» (control room S4) prop types. Task 13 completes this file.

export interface RatingSummary {
    count: number;
    avg: number | null;
    low: number;
}

/** The ratings section of /reports/team (G11). */
export interface TeamRatings {
    stars: 'low' | 'all';
    summary: RatingSummary;
    by_agent_day: { user: { id: number; name: string; color: string | null }; date: string; count: number; avg: number | null; low: number }[];
    list: {
        entry_id: number;
        conversation_id: number | null;
        stars: number;
        reviewed_at: string;
        user: { id: number; name: string } | null;
        customer: string | null;
    }[];
}
