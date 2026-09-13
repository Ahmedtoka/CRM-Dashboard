// Shapes for the Task 10 screens (comments, orders list, customers, reports, settings, simulator).
import type { Order, PlatformValue, Role, UserRef } from '@/types/crm';

export type CommentStatus = 'new' | 'replied' | 'hidden' | 'ignored';
export type CommentIntent = 'buy' | 'question' | 'complaint' | 'spam' | 'other';

export interface PostRef {
    id: number;
    platform: PlatformValue | null;
    caption: string | null;
    permalink: string | null;
    thumbnail_url: string | null;
    is_ad: boolean;
}

export interface CommentItem {
    id: number;
    post: PostRef | null;
    customer: { id: number; name: string | null; avatar_url: string | null } | null;
    parent_external_id: string | null;
    body: string | null;
    status: CommentStatus | null;
    intent: CommentIntent | null;
    public_reply: string | null;
    public_replied_at: string | null;
    replied_by_type: 'user' | 'bot' | 'system' | 'customer' | null;
    replied_by_id: number | null;
    replied_by?: { id: number; name: string } | null;
    private_reply_sent_at: string | null;
    conversation_id: number | null;
    created_at: string | null;
}

/** CommentUpdated broadcast payload. */
export type CommentPatch = Partial<CommentItem> & { id: number; post_id?: number };

export interface CommentFilters {
    status: CommentStatus | null;
    intent: CommentIntent | null;
    platform: PlatformValue | null;
    post_id: number | null;
}

export type Capabilities = Record<PlatformValue, { private_reply: boolean; hide_comment: boolean }>;

/** Laravel length-aware paginator as serialized by a resource collection. */
export interface Paginated<T> {
    data: T[];
    links?: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta?: { current_page: number; last_page: number; from: number | null; to: number | null; total: number; per_page: number };
}

export interface OrderRow extends Order {
    financial_status?: string | null;
    fulfillment_status?: string | null;
    shopify_order_id?: string | null;
    shopify_draft_order_id?: string | null;
    shipping?: { name: string | null; phone: string | null; city: string | null; address: string | null };
    note?: string | null;
    paid_at?: string | null;
}

export interface ReportRange {
    from: string;
    to: string;
}

export interface TeamMetrics {
    inbound_messages: number;
    outbound_messages: number;
    bot_messages: number;
    conversations_new: number;
    conversations_resolved: number;
    waiting_now: number;
    needs_human_now: number;
    avg_first_response_sec: number;
    comments_total: number;
    comments_by_status: Record<string, number>;
    orders_count: number;
    orders_total: number;
    by_platform: Record<PlatformValue, { inbound: number; outbound: number; comments: number; orders_count: number; orders_total: number }>;
    by_hour: number[];
}

export interface UserMetrics {
    messages_sent: number;
    conversations_handled: number;
    first_responses: number;
    continued: number;
    follow_ups: number;
    resolved: number;
    avg_first_response_sec: number;
    avg_response_sec: number;
    comments_handled: number;
    private_replies: number;
    orders_count: number;
    orders_total: number;
    cod_count: number;
    payment_link_count: number;
    payment_link_paid: number;
    online_minutes: number;
    by_platform: Record<PlatformValue, { messages_sent: number; orders_count: number }>;
}

export type LeaderboardRow = UserMetrics & { user: UserRef };

export interface BotMetrics {
    messages_sent: number;
    conversations_touched: number;
    auto_resolved: number;
    handovers: number;
    handover_rate: number;
    handover_reasons: Record<string, number>;
    rule_hits: { rule_id: number; name: string | null; hits: number }[];
    ai_runs: number;
    ai_cost_usd: number;
    comments_replied: number;
    comments_hidden: number;
    private_replies: number;
}

/** hourlyHeatmap: [weekday 0 (Sunday)–6][hour 0–23]. */
export type HeatmapGrid = number[][];

export interface ManagedUser {
    id: number;
    name: string;
    email: string;
    role: Role;
    color: string | null;
    locale: 'ar' | 'en' | null;
    is_active: boolean;
    platforms: PlatformValue[];
    last_seen_at: string | null;
}

export type LatencyKind = 'inbound' | 'outbound' | 'list';
export type LatencyWindow = '15m' | '1h' | '24h' | 'custom';

export interface LatencyKindStats {
    count: number;
    p50: number;
    p95: number;
    p99: number;
    max: number;
    target: number;
    /** null when count is 0 — no data means neither pass nor fail. */
    pass: boolean | null;
}

export interface ActivityLogItem {
    id: number;
    actor_type: 'user' | 'bot' | 'system' | 'customer' | null;
    user: UserRef | null;
    action: string;
    subject_type: string | null;
    subject_id: number | null;
    conversation_id: number | null;
    platform: PlatformValue | null;
    meta: Record<string, unknown> | null;
    created_at: string | null;
}

export interface BotSettings {
    enabled: boolean;
    ai_enabled: boolean;
    ai_classifier_model: string | null;
    ai_reply_model: string | null;
    system_prompt: string | null;
    min_confidence: string | number;
    max_bot_turns: number;
    handover_keywords: string[] | null;
    comment_reply_delay_min: number;
    comment_reply_delay_max: number;
    working_hours: { from?: string | null; to?: string | null; days?: number[] | null } | null;
    outside_hours_message: string | null;
    spam_phrases: string[] | null;
    low_value_phrases: string[] | null;
    allowed_link_domains: string[] | null;
    spam_repeat_threshold: number;
}

export type RuleScope = 'comment' | 'message' | 'both';
export type RuleMatchType = 'any_keyword' | 'all_keywords' | 'exact' | 'regex';
export type RuleAction = 'reply' | 'reply_and_handover' | 'handover' | 'hide';

export interface BotRule {
    id: number;
    name: string;
    is_active: boolean;
    priority: number;
    scope: RuleScope;
    platforms: PlatformValue[] | null;
    match_type: RuleMatchType;
    keywords: string[];
    public_replies: string[] | null;
    private_reply: string | null;
    action: RuleAction;
    hits: number;
}

export type BotRuleInput = Omit<BotRule, 'id' | 'hits'>;

export interface RuleTestResult {
    rule: { id: number; name: string; action: RuleAction; public_replies: string[] | null; private_reply: string | null } | null;
    normalized: string;
    ai_classification: { intent?: string; confidence?: number; needs_human?: boolean; model?: string; error?: string };
}

export interface ChannelAccount {
    id: number;
    platform: PlatformValue;
    name: string;
    external_id: string | null;
    driver: 'fake' | 'live';
    status: 'connected' | 'error' | 'disconnected';
    last_webhook_at: string | null;
    last_error: string | null;
    has_token: boolean;
    linked_facebook_account_id: number | null;
    webhook_url: string;
    verify_token: string | null;
}

export interface ChannelTestResult {
    ok: boolean;
    page_name?: string;
    ig_username?: string;
    error?: string;
    note?: string;
}

export interface FailedWebhookEvent {
    id: number;
    provider: string;
    event_type: string | null;
    status: string;
    attempts: number;
    error: string | null;
    created_at: string | null;
}

export interface QuickReplyRow {
    id: number;
    shortcut: string;
    title: string;
    body: string;
    platforms: PlatformValue[] | null;
    creator: { id: number; name: string } | null;
}

export interface TagRow {
    id: number;
    name: string;
    color: string | null;
    conversations_count: number;
}

export interface CityRow {
    id: number;
    name_ar: string;
    name_en: string | null;
    shipping_fee: string | number;
}

export interface SimShipment {
    id: number;
    order_id: number;
    order_number: string | null;
    status: string | null;
    tracking_number: string | null;
}

export interface SimPost {
    id: number;
    platform: PlatformValue;
    external_id: string;
    caption: string | null;
    is_ad: boolean;
}
