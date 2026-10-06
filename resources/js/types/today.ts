// «النهارده» (control room S4): the props of /today, /reports/team ratings and the board's rating numbers.

export type TodayMode = 'today' | 'yesterday';

export type UrgentKey = 'overdue_windows' | 'lounge' | 'low_ratings' | 'unpaid_orders' | 'cases_overdue' | 'ads_sync' | 'launches';

export interface UrgentItem {
    key: UrgentKey;
    count: number;
    tone: 'danger' | 'warn';
    href: string;
    longest_wait_seconds?: number | null;
    age_minutes?: number | null;
}

export interface RatingSummary {
    count: number;
    avg: number | null;
    low: number;
}

export interface QueueDayFigures {
    issued: number;
    closed: Record<string, number>;
    closed_manual: number;
    closed_total: number;
    abandoned: number;
    sla_replied: number;
    sla_pct: number | null;
    sla_target_pct: number;
    avg_wait_seconds: number | null;
}

export interface ChatsCard {
    new: number;
    from_ads: number;
    ads_share: number | null;
    bot_alone: number;
    to_agent: number;
    first_reply_avg_sec: number;
    queue: QueueDayFigures | null;
    rating: RatingSummary;
    links: Record<'new' | 'ads' | 'bot' | 'first_reply' | 'rating' | 'queue', string>;
}

export interface OrdersCard {
    count: number;
    total: number;
    from_chat: number;
    from_store: number;
    cancelled: number;
    failed: number;
    links: Record<'count' | 'from_chat' | 'from_store' | 'cancelled' | 'failed', string>;
}

export interface AdRef {
    id: number;
    name: string;
}

export interface AdsCard {
    from: string;
    to: string;
    currency: string;
    spend: number | null;
    real_orders: number;
    real_roas: number | null;
    meta_roas: number | null;
    cost_per_order: number | null;
    best: (AdRef & { orders: number }) | null;
    loser: (AdRef & { spend: number }) | null;
    links: { spend: string; orders: string; best: string | null; loser: string | null };
}

export interface WhyCard {
    total: number;
    ordered: number;
    reasons: { key: string; count: number; share: number }[];
    top_size_out: (AdRef & { count: number }) | null;
    links: { reasons: string; top_size_out: string | null };
}

export interface TodayCardsData {
    chats: ChatsCard;
    orders: OrdersCard;
    ads: AdsCard | null;
    why: WhyCard;
}

export interface TeamRow {
    user: { id: number; name: string; color: string | null };
    online: boolean;
    desk_status: string | null;
    windows_closed: number;
    orders: number;
    rating: RatingSummary;
    href: string;
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
