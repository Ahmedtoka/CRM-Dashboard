// Shapes for the Task 10 screens (comments, orders list, customers, reports, settings, simulator).
import type { Order, PlatformValue, QuickReplyScope, Role, UserRef } from '@/types/crm';

export type CommentStatus = 'new' | 'replied' | 'hidden' | 'ignored';
export type CommentIntent = 'buy' | 'question' | 'complaint' | 'spam' | 'other';

export interface PostRef {
    id: number;
    platform: PlatformValue | null;
    caption: string | null;
    permalink: string | null;
    thumbnail_url: string | null;
    is_ad: boolean;
    /** The ad behind the post (2026-09-25), when known. */
    ad?: { id?: string; title?: string; name?: string; adset?: string; campaign?: string } | null;
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
    flows: FlowUsageRow[];
}

/** Reports → Bot: one guided flow's usage in the selected period (MetricsService::flowUsage). */
export interface FlowUsageRow {
    key: string;
    title: string;
    is_active: boolean;
    started: number;
    finished: number;
    handovers: number;
    cases: number;
    records_cases: boolean;
}

/** hourlyHeatmap: [weekday 0 (Sunday)–6][hour 0–23]. */
export type HeatmapGrid = number[][];

export interface QuickReplyTopRow {
    id: number;
    shortcut: string;
    title: string;
    scope: QuickReplyScope;
    category: string | null;
    uses: number;
    users: number;
    platforms: Partial<Record<PlatformValue, number>>;
    last_used_at: string | null;
}

export interface QuickReplyAgentRow {
    user: UserRef;
    uses: number;
    replies: number;
}

export interface QuickReplyUnusedRow {
    id: number;
    shortcut: string;
    title: string;
    scope: QuickReplyScope;
    last_used_at: string | null;
}

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
    non_returnable_keywords: string[] | null;
    spam_repeat_threshold: number;
    burst_wait_seconds: number;
    burst_max_wait_seconds: number;
    typing_ms_per_char: number;
    order_lookup_enabled: boolean;
    /** the «🛍️ تسوقي من الموقع» button's link (empty = the default store) */
    store_url?: string | null;
}

export type BotIntentRoute = 'answer' | 'lookup' | 'collect_then_handover' | 'handover';

/** One row of `bot_intents` (settings/BotIntents). */
export interface BotIntentRow {
    id: number;
    key: string;
    group: string;
    label_ar: string;
    label_en: string;
    route: BotIntentRoute;
    flow_key: string | null;
    priority: 'low' | 'medium' | 'high';
    queue: 'agents' | 'senior' | null;
    script_keys: string[] | null;
    required_details: string[] | null;
    keywords: string[] | null;
    is_active: boolean;
    sort: number;
}

/** A guided flow an intent can start (bot_flows). */
export interface BotFlowOption {
    key: string;
    title_ar: string;
    is_active: boolean;
}

/** A `script.*` knowledge entry an intent can answer with. */
export interface BotScriptOption {
    id: number;
    key: string;
    title: string;
    is_active: boolean;
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

/** Outcome of the "Connect with Facebook" flow, flashed back to Settings → Channels. */
export interface FacebookConnectFlash {
    code: string;
    name?: string;
    detail?: string;
}

export interface FacebookLoginSettings {
    enabled: boolean;
    secret_missing: boolean;
    redirect_uri: string;
    flash: FacebookConnectFlash | null;
}

/** A Page from `GET /me/accounts` as shown on the picker — never carries a token. */
export interface FacebookPageOption {
    id: string;
    name: string;
    category: string | null;
    picture: string | null;
    tasks: string[] | null;
    missing_tasks: string[];
    connected: boolean;
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
    scope: 'shared' | 'personal';
    category_id: number | null;
    use_count: number;
    last_used_at: string | null;
    creator: { id: number; name: string } | null;
    attachments: { id: number; type: 'image' | 'file'; original_name: string | null; thumb_url: string | null }[];
}

export interface QuickReplyVariable {
    key: string;
    alias: string;
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

export interface BranchRow {
    id: number;
    governorate: string;
    area_key: string;
    area_ar: string;
    area_en: string | null;
    name: string;
    address: string;
    phone: string | null;
    map_url: string | null;
    hours: string | null;
    aliases: string[] | null;
    is_active: boolean;
    sort: number;
}

/** Daily learning (design §6): one nightly report and the suggestions waiting for approval. */
export interface BotLearningStats {
    conversations?: number;
    handovers?: number;
    cases?: number;
    top_intents?: string[];
    /** Learning v2: counted from the day's per-conversation notes. */
    conversations_reviewed?: number;
    notes?: number;
    cost_usd?: number;
    sources?: { channel_account_id: number; name: string; count: number }[];
    /** A report written before learning v2, from the demo conversations. */
    demo?: boolean;
}

export type BotLearningNoteKind = 'unanswered' | 'wrong_answer' | 'agent_knowledge' | 'new_phrasing' | 'flow_friction';

/** One note of today's per-conversation reviews, flattened for the "ملاحظات النهارده" tab. */
export interface BotLearningNoteRow {
    id: string;
    conversation_id: number | null;
    /** Where the episode came from (design 2026-09-21 §5). */
    source: BotLearningSource;
    channel_account: string | null;
    kind: BotLearningNoteKind;
    summary: string;
    quote: string;
    agent_answer: string | null;
    created_at: string | null;
}

/** A learning note or suggestion came from a real customer or from a team test run. */
export type BotLearningSource = 'live' | 'test';

export interface BotLearningToday {
    reviewed: number;
    notes: number;
    cost_usd: number;
    cap: number;
    live: number;
    test: number;
}

export interface BotLearningReportRow {
    id: number;
    report_date: string;
    summary: string | null;
    stats: BotLearningStats | null;
    model: string | null;
    input_tokens: number;
    output_tokens: number;
    pending_count?: number;
}

export interface BotSuggestionRow {
    id: number;
    type: 'script_text' | 'new_faq' | 'intent_keywords' | 'flow_step';
    source: BotLearningSource;
    target: string | null;
    /** The target in words (script title, intent name, flow › step); null when unknown. */
    target_label?: string | null;
    current: { body?: string; keywords?: string[]; text?: string | null; options?: string[] } | null;
    proposed: {
        body?: string;
        key?: string;
        title?: string;
        keywords?: string[];
        add?: string[];
        text?: string;
        options?: { index: number; title: string }[];
    };
    reason: string | null;
    evidence: { conversation_ids?: number[]; quote?: string } | null;
    status: 'pending' | 'approved' | 'rejected';
    decided_at: string | null;
    applied_at: string | null;
    error: string | null;
}

export interface BotLearningReport extends BotLearningReportRow {
    suggestions: BotSuggestionRow[];
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

// Shopify connection screen (Task 8, spec §7).
export type ShopifyStatus = 'connected' | 'error' | 'disconnected';
export type ShopifyStage = 'shipping' | 'products' | 'customers' | 'orders';
export type ShopifySyncResource = 'shipping' | 'products' | 'customers' | 'orders';

export interface ShopifyStageState {
    status: 'pending' | 'running' | 'completed' | 'failed';
    total: number | null;
    processed: number;
    failed: number;
    bulk_operation_id: string | null;
}

export interface ShopifyImportState {
    stages?: Partial<Record<ShopifyStage, ShopifyStageState>>;
    orders_since?: string;
}

export interface ShopifyIntegrationRow {
    shop_domain: string;
    shop_name: string | null;
    currency: string | null;
    status: ShopifyStatus;
    last_error: string | null;
    connected_at: string | null;
    settings: {
        default_shipping_fee: number;
        auto_create_shipment: boolean;
        stuck_order_days: number;
        mismatch_alerts: boolean;
        order_creation_enabled: boolean;
    };
    import_state: ShopifyImportState | null;
    granted_scopes: string[];
    missing_scopes: string[];
}

export interface ShopifyWebhookRow {
    topic: string;
    last_received_at: string | null;
    registered_at: string | null;
}

export interface ShopifySyncRunRow {
    id: number;
    type: string;
    resource: string;
    range_from: string | null;
    range_to: string | null;
    status: string;
    processed: number;
    created: number;
    updated: number;
    skipped_stale: number;
    failed: number;
    errors: { ref: string; message: string; at: string }[];
    started_at: string | null;
    finished_at: string | null;
}

export interface ShopifyLastSync {
    shipping: string | null;
    products: string | null;
    customers: string | null;
    orders: string | null;
}

export interface ShopifyTestResult {
    ok: boolean;
    shop_name?: string | null;
    currency?: string | null;
    missing_scopes?: string[];
    optional_scopes?: string[];
    error?: string | null;
}

export interface ShopifyStatusResponse {
    integration: ShopifyIntegrationRow | null;
    runs: ShopifySyncRunRow[];
    webhooks: ShopifyWebhookRow[];
}

// Bot knowledge base, size chart and settings screen (Task 10, spec §4.2).
export interface BotKnowledgeEntry {
    id: number;
    key: string;
    title: string;
    body: string;
    is_active: boolean;
    is_template: boolean;
    sort: number;
}

export interface SizeChartData {
    unit: string;
    columns: string[];
    rows: string[][];
    note: string | null;
}

export interface BotPreviewResult {
    reply: string | null;
    would_handover: boolean;
    reason: string | null;
    intent: string | null;
    grounding: string[];
}

/** Settings → Integrations. */
export type HealthLevel = 'ok' | 'warning' | 'problem';

export interface HealthCheckItem {
    key: 'token' | 'webhooks' | 'phone' | 'link' | 'inbound';
    status: HealthLevel;
    code: string;
    detail?: string;
    missing?: string[];
    fix?: 'reconnect' | 'reconnect_facebook' | 'resubscribe';
}

export interface IntegrationHealth {
    status: HealthLevel;
    checked_at: string;
    checks: HealthCheckItem[];
}

/** A live Meta account as the Integrations page sees it — never carries a token. */
export interface IntegrationAccount {
    id: number;
    platform: 'facebook' | 'instagram' | 'whatsapp';
    name: string;
    external_id: string | null;
    status: 'connected' | 'error' | 'disconnected';
    connected_at: string | null;
    last_inbound_at: string | null;
    last_webhook_at: string | null;
    last_error: string | null;
    has_token: boolean;
    profile: {
        picture: string | null;
        username: string | null;
        category: string | null;
        method: 'login' | 'system_user' | null;
        page_id: string | null;
        waba_id: string | null;
        display_phone_number: string | null;
        verified_name: string | null;
        quality_rating: string | null;
        override_callback: boolean;
    };
    health: IntegrationHealth | null;
    health_status: HealthLevel | null;
    health_checked_at: string | null;
}

export interface IntegrationAccounts {
    facebook: IntegrationAccount | null;
    instagram: IntegrationAccount | null;
    whatsapp: IntegrationAccount | null;
}

export interface IntegrationsMeta {
    app_id_set: boolean;
    app_secret_set: boolean;
    verify_token: string;
    callback_urls: { facebook: string; instagram: string; whatsapp: string };
    can_override_callback: boolean;
    required_scopes: Record<'facebook' | 'instagram' | 'whatsapp', { required: string[]; recommended: string[] }>;
}

export interface ShopifySummary {
    status: string;
    shop_name: string | null;
    shop_domain: string | null;
    connected_at: string | null;
    last_error: string | null;
}

/** An error answer from an Integrations endpoint (`IntegrationException::toArray()`). */
export interface IntegrationError {
    ok: false;
    error: string;
    detail: string | null;
}

export interface WhatsAppPhoneOption {
    id: string;
    display_phone_number: string | null;
    verified_name: string | null;
    quality_rating: string | null;
    code_verification_status: string | null;
}

export interface SystemTokenPage {
    id: string;
    name: string;
    category: string | null;
    picture: string | null;
    missing_tasks: string[];
}

/** Settings → روابط التجربة (design 2026-09-21 §1): one public link the owner shares. */
export interface TestLinkRow {
    id: number;
    label: string;
    token: string;
    url: string;
    is_active: boolean;
    /** Running and not expired — what /try/{token} actually checks. */
    is_open: boolean;
    expires_at: string | null;
    max_sessions: number | null;
    max_messages_per_session: number;
    views_count: number;
    sessions_count: number;
    runs_count: number;
    live_sessions: number;
    last_opened_at: string | null;
    created_at: string | null;
}

/** One tester's run of a test link. */
export interface TestLinkSessionRow {
    id: number;
    label: string;
    name: string;
    run_no: number;
    conversation_id: number | null;
    device_family: string | null;
    messages_count: number;
    started_at: string | null;
    last_seen_at: string | null;
    ended_at: string | null;
    ended_reason: string | null;
    duration_seconds: number;
}

/** Reports → تجربة الفريق (design 2026-09-21 §4): one row per run. */
export interface TeamTestSessionRow {
    id: number;
    link_id: number;
    link_label: string | null;
    name: string;
    run_no: number;
    label: string;
    conversation_id: number | null;
    device_family: string;
    started_at: string | null;
    ended_at: string | null;
    ended_reason: string | null;
    duration_seconds: number;
    messages_in: number;
    messages_out: number;
    messages_total: number;
    flows: { key: string; title: string }[];
    last_flow: string | null;
    last_step: string | null;
    finished: boolean;
    dropped_at: string | null;
    cases: number;
    case_types: string[];
    handovers: number;
}

export interface TeamTestFunnel {
    key: string;
    title: string;
    entered: number;
    finished: number;
    steps: { id: string; reached: number; dropped: number }[];
    top_drop_offs: { id: string; dropped: number }[];
}

export interface TeamTestTotals {
    sessions: number;
    testers: number;
    messages: number;
    cases: number;
    handovers: number;
    finished: number;
    avg_duration_seconds: number;
}

export interface TeamTestLinkOption {
    id: number;
    label: string;
    url: string;
    is_open: boolean;
    views_count: number;
    runs_count: number;
    last_opened_at: string | null;
}

export interface TeamTestTranscriptLine {
    id: number;
    direction: 'in' | 'out';
    sender: 'customer' | 'bot' | 'user' | 'system';
    author: string | null;
    body: string | null;
    buttons: { title: string; payload: string }[];
    has_cards: boolean;
    has_image: boolean;
    created_at: string | null;
}

/** Settings → الترجمات (design 2026-09-21 §2): one Arabic text of the bot and its English. */
export interface BotTranslationRow {
    id: number | null;
    source: string;
    text: string | null;
    origin: 'auto' | 'human' | null;
    context: string;
    short: boolean;
    orphan: boolean;
}

export interface BotTranslationUsage {
    used: number;
    cap: number;
}
