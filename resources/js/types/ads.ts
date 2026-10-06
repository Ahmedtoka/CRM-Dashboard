/**
 * Ads Hub page props — mirrors app/Http/Controllers/Web/Ads/* and app/Ads/Reports/* exactly
 * (AdsQuery::derive, AdsOverview, BuyerScorecard, RunningCreatives, WinnerScorer).
 * Money is pre-tax unless the key ends in `_tax`; ratios (ctr, cvr) are fractions, not percents.
 */

export type AdPlatformValue = 'meta' | 'tiktok' | 'google';

/** Shared `ads` prop (HandleInertiaRequests), present only on ads.* routes. */
export interface AdsAccess {
    canSeeSpend: boolean;
    canManage: boolean;
    /** Stop / Run buttons (the account itself is checked on the server). */
    canWrite: boolean;
    isBuyer: boolean;
    buyerId: number | null;
    /** Approve launches (Ads authority). */
    canApprove: boolean;
    /** Admin-only direct publish (O7). */
    canDirectPublish: boolean;
}

export interface AdsOption {
    id: number;
    name: string;
}

/** BuildsAdsPages::filterProps — the filter as it was applied. */
export interface AdsFilters {
    from: string;
    to: string;
    platform: AdPlatformValue | null;
    buyer: number | null;
}

/** AdsQuery::derive */
export interface AdsDerived {
    spend: number;
    spend_tax: number;
    purchase_value: number;
    roas: number | null;
    purchases: number;
    cpa: number | null;
    impressions: number;
    clicks: number;
    ctr: number | null;
    reach: number;
    cpm: number | null;
    cpc: number | null;
}

/** Money fields are null when the filtered accounts mix currencies (AdsOverview, A9). */
export interface AdsTotals extends Omit<AdsDerived, 'spend' | 'spend_tax' | 'purchase_value'> {
    spend: number | null;
    spend_tax: number | null;
    purchase_value: number | null;
    mixed_currencies: boolean;
    /** Share (0..1) of the spend that went to loser-tier ads; null without spend. */
    losers_spend_share: number | null;
    /** Spend of campaigns that are not active (paused, archived, deleted); already inside `spend`. */
    spend_outside_active: number | null;
    /** Where spend, purchase value and ROAS come from: the account-level control or the sum of ads. */
    source: 'account' | 'mixed' | 'ads';
    /** Control spend minus the sum of ads (Meta no longer itemises it by ad); null when source is 'ads'. */
    itemised_gap: number | null;
    /** none: within tolerance; unitemised: control above ads; updating: control below ads (platform still settling). */
    gap_state: 'none' | 'unitemised' | 'updating';
    real_orders: number;
    real_revenue: number | null;
    real_roas: number | null;
    conversations: number;
    conversations_ordered: number;
}

export interface AdsDailyRow {
    date: string;
    spend: number;
    spend_tax: number;
    purchase_value: number;
    roas: number | null;
    purchases: number;
    impressions: number;
    clicks: number;
    ctr: number | null;
    cpm: number | null;
    cpc: number | null;
    reach: number;
    real_orders: number;
    real_revenue: number;
}

export interface AdsPlatformRow {
    platform: AdPlatformValue;
    spend: number;
    spend_tax: number;
    purchase_value: number;
    roas: number | null;
    accounts: number;
}

export interface AdsOverviewData {
    totals: AdsTotals;
    daily: AdsDailyRow[];
    platforms: AdsPlatformRow[];
    currency: string;
    tax_rate: number;
}

/** TopAccounts::build — accounts with spend in range, biggest first; spend pre-tax plus spend_tax. */
export interface AdsTopAccountRow {
    id: number;
    name: string;
    external_id: string;
    platform: AdPlatformValue;
    currency: string;
    buyer: string | null;
    spend: number;
    spend_tax: number;
    purchase_value: number;
    purchases: number;
    roas: number | null;
    status: string | null;
    last_synced_at: string | null;
}

export interface AdsSync {
    last_synced_at: string | null;
    /** The active in-scope account synced longest ago (never synced sorts first); null when no account is in scope. */
    oldest: { account: string; last_synced_at: string | null } | null;
    errors: { account: string; error: string }[];
}

/** One data-health reason for the accounts of the current filter (DataHealth::forFilter). */
export interface AdsDataHealthReason {
    reason: 'reconnect' | 'stale' | 'read_only' | 'incomplete' | 'gap' | 'timezone';
    /** For `incomplete`: the accounts never judged yet (at most three names). */
    unverified?: string[];
    /** For `incomplete`: never-judged accounts beyond the three named. */
    unverified_more?: number;
    /** At most three account names. */
    accounts: string[];
    /** Accounts beyond the three named. */
    more: number;
    /** For `stale`: the hours after which an account counts as behind (crm.ads.health.stale_after_hours). */
    hours?: number;
}

export interface AdsDataHealth {
    reasons: AdsDataHealthReason[];
}

/** BuildsAdsPages::bannerProps: what the data-health banner shows. */
export interface AdsBannerProps {
    data_health: AdsDataHealth;
    /** True until the owner passes the Phase A gate (ads:gate --pass). */
    numbers_under_review: boolean;
    /** The range was cut at the history start. */
    clamped_to_history: boolean;
}

/** BuildsAdsPages::commonProps — on every report page. `buyers` is empty for media buyers. */
export interface AdsCommonProps extends AdsBannerProps {
    buyers: AdsOption[];
    platforms: AdPlatformValue[];
    currency: string;
}

/** RevenueSummary::build; `store` is null for media buyers. */
export interface AdsRevenueSummary {
    currency: string;
    /** Set when the one currency in scope is not EGP: store and CRM ROAS are not comparable. */
    note?: 'foreign_currency' | null;
    mixed_currencies: boolean;
    spend: number | null;
    spend_tax: number | null;
    store: { orders: number; revenue: number | null } | null;
    crm: { orders: number; revenue: number | null; chat_orders: number };
    platform: { purchases: number; revenue: number | null };
    roas: { store: number | null; crm: number | null; platform: number | null };
    gaps: { platform_vs_crm: number | null; crm_vs_store: number | null; platform_vs_crm_pct: number | null; crm_vs_store_pct: number | null };
}

export interface AdsOverviewProps extends AdsCommonProps {
    filters: AdsFilters;
    overview: AdsOverviewData;
    summary: AdsRevenueSummary;
    top_accounts: AdsTopAccountRow[];
    sync: AdsSync;
}

/** BuyerScorecard::card — buyer_id null is the «unassigned» card. */
export interface BuyerCardData {
    buyer_id: number | null;
    name: string;
    color: string | null;
    accounts: { id: number; name: string; platform: AdPlatformValue }[];
    spend: number | null;
    spend_tax: number | null;
    purchase_value: number | null;
    roas: number | null;
    purchases: number;
    cpa: number | null;
    ctr: number | null;
    real_orders: number;
    real_revenue: number | null;
    /** True when the buyer's accounts have several currencies: every money figure is then null. */
    mixed_currencies?: boolean;
    real_roas: number | null;
    conversations: number;
    conversations_ordered: number;
    budget: number | null;
    budget_used_pct: number | null;
    target_roas: number | null;
    roas_vs_target: number | null;
}

export interface BuyerAssignment {
    account_id: number;
    account: string;
    platform: AdPlatformValue;
    starts_on: string;
    ends_on: string | null;
}

export interface BuyerCampaignRow {
    id: number | null;
    name: string | null;
    status: string | null;
    account: string | null;
    platform: AdPlatformValue | null;
    spend: number;
    spend_tax: number;
    purchase_value: number;
    roas: number | null;
    purchases: number;
    cpa: number | null;
    ctr: number | null;
    real_orders: number;
    real_revenue: number;
}

export interface BuyerDetail extends BuyerCardData {
    daily: AdsDailyRow[];
    assignments: BuyerAssignment[];
    top_ads: CreativeRow[];
    campaigns: BuyerCampaignRow[];
}

export interface AdsBuyersProps extends AdsCommonProps {
    filters: AdsFilters;
    cards: BuyerCardData[];
}

export interface AdsBuyerShowProps extends AdsCommonProps {
    /** BuildsAdsPages::filterProps without the request: range key and an empty accounts list. */
    filters: AdsFilters & { range: AdsRangeKey | null; accounts: number[] };
    buyer: { id: number; name: string; color: string | null };
    /** Oldest last sync of the accounts in scope (data age for the Stop dialog). */
    freshness: string | null;
    detail: BuyerDetail;
    summary: AdsRevenueSummary;
}

/** RunningCreatives::rows */
/** AdInsights::forAds — last 7 days vs the 7 before, and creative fatigue. */
export interface AdTrend {
    roas_pct: number | null;
    spend_pct: number | null;
    dir: 'up' | 'down' | 'flat';
}

export interface AdFatigue {
    flag: boolean;
    ctr_drop_pct: number | null;
    frequency: number | null;
}

/** A written reason from WinnerScorer, translated on the client (ads.reasons.{key}). */
export interface AdReason {
    key: string;
    params: Record<string, number | string>;
}

export interface CreativeRow {
    id: number;
    external_id: string;
    name: string;
    platform: AdPlatformValue;
    account: string;
    account_id: number;
    campaign: string | null;
    adset: string | null;
    type: string | null;
    status: string | null;
    effective_status: string | null;
    /** The campaign or ad set above the ad is paused (the ad's own status can still be active). */
    parent_paused: boolean;
    /** Stop / Run allowed on this row's account today (list rows only). */
    can_write?: boolean;
    thumbnail_url: string | null;
    image_url: string | null;
    video_url: string | null;
    preview_url: string | null;
    permalink_url: string | null;
    instagram_permalink_url: string | null;
    object_story_id: string | null;
    headline: string | null;
    body: string | null;
    created_time: string | null;
    impressions: number;
    clicks: number;
    ctr: number | null;
    purchases: number;
    spend: number;
    spend_tax: number;
    purchase_value: number;
    roas: number | null;
    real_orders: number;
    buyer: string | null;
    trend: AdTrend;
    fatigue: AdFatigue;
}

/** GET /ads/creatives/{ad} (RunningCreatives::detail) */
export interface CreativeDetail extends CreativeRow {
    preview_html: string | null;
    reasons: AdReason[];
}

export type CreativeStatusFilter = 'all' | 'active' | 'inactive';
export type CreativeSort = 'spend' | 'roas' | 'ctr' | 'impressions' | 'clicks' | 'purchases' | 'date';
export type CreativePerPage = 10 | 25 | 50 | 100;

export interface CreativesFilters extends AdsFilters {
    status: CreativeStatusFilter;
    account: number | null;
    sort: CreativeSort;
    per_page: CreativePerPage;
    q: string | null;
    page: number;
}

export interface CreativesResult {
    data: CreativeRow[];
    meta: { total: number; per_page: number; current_page: number; last_page: number };
    counts: { all: number; active: number; inactive: number };
    accounts: { id: number; name: string; count: number }[];
    totals: AdsDerived;
}

export interface AdsCreativesProps extends AdsCommonProps {
    filters: CreativesFilters;
    result: CreativesResult;
}

/** CampaignTree::build — Metrics = AdsQuery::derive plus the ad-attributed real orders. */
export interface CampaignMetrics extends AdsDerived {
    real_orders: number;
    /** Store revenue of the orders the node's ads brought (EGP). */
    real_revenue?: number;
    /** real_revenue / spend; null off EGP or without spend. */
    real_roas?: number | null;
}

/** A node of the campaign tree. id 0 = the placeholder for ads without a campaign / ad set. Ad nodes carry ad_id and trend. */
export interface CampaignNode {
    level: 'campaign' | 'adset' | 'ad';
    /** True for the «no campaign» / «no ad set» stand-ins (id 0): never a target for Stop / Run. */
    placeholder: boolean;
    id: number;
    ad_id?: number;
    external_id: string;
    account_id: number;
    account: string;
    platform: AdPlatformValue | string;
    name: string;
    status: string | null;
    objective: string | null;
    naming_ok: boolean;
    /** Ad nodes: the campaign or ad set above is paused. */
    parent_paused: boolean;
    /** Stop / Run allowed on this node's account today. */
    can_write?: boolean;
    metrics: CampaignMetrics;
    trend?: AdTrend | null;
    children: CampaignNode[];
}

export type CampaignSort = 'spend' | 'roas';

export interface AdsCampaignsProps extends AdsCommonProps {
    filters: AdsFilters & { sort: CampaignSort; accounts: number[] };
    /** Accounts in the user's scope (not narrowed by the picked ones). */
    account_options: { id: number; name: string; platform: AdPlatformValue }[];
    tree: CampaignNode[];
}

export type WinnerTier = 'winner' | 'promising' | 'loser' | 'neutral';
export type WinnerSort = 'score' | 'roas' | 'spend' | 'revenue' | 'date';

/** WinnerScorer::build — spend pre-tax (the ad row carries spend_tax), cvr/ctr fractions. */
export interface WinnerRow {
    ad: CreativeRow;
    score: number;
    tier: WinnerTier;
    smoothed_roas: number;
    blended_roas: number;
    roas: number | null;
    spend: number;
    revenue: number;
    orders: number;
    cpa: number | null;
    ctr: number | null;
    cvr: number | null;
    active_days: number;
    days_with_sales: number;
    recommendation: string;
    reasons: AdReason[];
    trend: AdTrend;
    fatigue: AdFatigue;
}

/** Tier chips; `top` = winner + promising (the default). */
export type WinnerTierFilter = 'top' | 'all' | WinnerTier;

export interface WinnersFilters extends AdsFilters {
    status: CreativeStatusFilter;
    sort: WinnerSort;
    tier: WinnerTierFilter;
    page: number;
}

export interface AdsWinnersProps extends AdsCommonProps {
    filters: WinnersFilters;
    window: { from: string; to: string };
    /** The current page of the selected tier (20 per page). */
    winners: WinnerRow[];
    meta: { total: number; per_page: number; current_page: number; last_page: number };
    tier_counts: Record<WinnerTierFilter, number>;
}

/* ---- Setup pages: AccountController::index and BuyerSetupController::index ---- */

export type AdConnectionStatus = 'connected' | 'pending' | 'error' | 'needs_reconnect' | 'disabled';

/** AssignmentService::history — newest period first; `ends_on` null = the open period. */
export interface AdAssignmentPeriod {
    /** null = a period where the account was explicitly unassigned. */
    buyer_id: number | null;
    buyer: string | null;
    starts_on: string;
    ends_on: string | null;
}

export interface AdAccountRow {
    id: number;
    external_id: string;
    name: string;
    currency: string;
    status: string | null;
    is_active: boolean;
    last_synced_at: string | null;
    buyer: AdsOption | null;
    history: AdAssignmentPeriod[];
    spend_30d: number;
}

export interface AdTokenHealth {
    valid: boolean | null;
    type: string | null;
    scopes: string[];
    expires_at: string | null;
    data_access_expires_at: string | null;
    checked_at: string | null;
}

export interface AdConnectionRow {
    id: number;
    platform: AdPlatformValue;
    name: string;
    status: AdConnectionStatus;
    last_error: string | null;
    last_synced_at: string | null;
    driver: 'live' | 'fake';
    has_token: boolean;
    /** The stored ciphertext cannot be decrypted: the token must be entered again. */
    credentials_unreadable: boolean;
    /** The token has no ads_management permission: the CRM refuses changes on this connection. */
    read_only: boolean;
    /** What the daily probe learned about the token (never the token). */
    token_health: AdTokenHealth;
    /** Which credential fields hold a stored value; the values never leave the server. */
    configured: Record<string, boolean>;
    accounts: AdAccountRow[];
}

export interface AdPlatformField {
    key: string;
    /** Translated server-side (lang/ads.php `credentials.*`). */
    label: string;
    secret: boolean;
}

export interface AdPlatformDefinition {
    value: AdPlatformValue;
    label: string;
    fields: AdPlatformField[];
}

/** AccountController::index — active buyers plus any archived buyer who still holds an account. */
export interface AdBuyerOption extends AdsOption {
    is_active: boolean;
}

export type AdsSyncStatus = 'running' | 'ok' | 'error';
export type AdsSyncTrigger = 'schedule' | 'manual' | 'backfill' | 'setup';

/** SyncController::run — one row of ads_sync_runs. */
export interface AdsSyncRunRow {
    id: number;
    account: string | null;
    platform: string;
    kind: string;
    status: AdsSyncStatus;
    from: string | null;
    to: string | null;
    ads_count: number | null;
    rows_count: number | null;
    error: string | null;
    trigger: AdsSyncTrigger | null;
    user: string | null;
    started_at: string | null;
    finished_at: string | null;
    seconds: number | null;
}

/** QueueInspector::waiting — a job still in the commercelong queue. */
export interface AdsSyncWaitingRow {
    job: string;
    account_id: number | null;
    account: string | null;
    kind: string | null;
    days: number | null;
    attempts: number;
    available_at: string | null;
    trigger: AdsSyncTrigger | null;
}

export interface AdsSyncScheduleRow {
    command: string;
    next_due: string;
}

export interface AdsSyncProps {
    now: { running: AdsSyncRunRow[]; waiting: AdsSyncWaitingRow[]; supported: boolean };
    runs: AdsSyncRunRow[];
    filters: { account: number | null; status: AdsSyncStatus | null; trigger: AdsSyncTrigger | null };
    schedule: AdsSyncScheduleRow[];
    accounts: { id: number; name: string; platform: string }[];
}

export interface AdsAccountsProps {
    connections: AdConnectionRow[];
    /** Share of the last `days` days' chat orders that carry a conversation; rate is null with no chat orders. */
    link_rate: { rate: number | null; orders: number; linked: number; days: number };
    /** Ids of accounts with a sync running or waiting in the queue. */
    syncing: number[];
    buyers: AdBuyerOption[];
    platforms: AdPlatformDefinition[];
}

export interface AdBuyerTarget {
    /** 'YYYY-MM' */
    month: string;
    budget: number;
    target_roas: number | null;
}

export interface AdBuyerSetupRow {
    id: number;
    name: string;
    color: string | null;
    is_active: boolean;
    user: AdsOption | null;
    targets: AdBuyerTarget[];
}

export interface AdsWinnerThresholds {
    winner: number;
    promising: number;
    loser: number;
    loser_min_spend: number;
    min_spend: number;
    min_days: number;
}

export interface AdsSetupSettings {
    /** Fraction (0.14). */
    tax_rate: number;
    tax_rate_percent: number;
    winner_thresholds: AdsWinnerThresholds;
}

export interface AdsBuyersSetupProps {
    buyers: AdBuyerSetupRow[];
    users: { id: number; name: string; role: string }[];
}

/* ---- Materials library: MaterialController, MaterialCollectionController, AdStockController ---- */

export type MaterialStatus = 'new' | 'in_review' | 'live' | 'paused' | 'retired';
export type MaterialStock = 'in' | 'out' | 'none';
export type MaterialType = 'reel' | 'carousel' | 'post' | 'story' | 'image' | 'video';

/** MaterialService::fileRow — urls are the authenticated file routes, never storage paths. */
export interface MaterialFile {
    id: number;
    url: string;
    thumb_url: string | null;
    mime: string | null;
    original_name: string | null;
    size: number | null;
}

export interface MaterialPerformance {
    spend: number | null;
    spend_tax: number | null;
    purchase_value: number | null;
    mixed_currencies?: boolean;
    roas: number | null;
    purchases: number;
    real_orders: number;
    winner_tier: WinnerTier | null;
}

export interface MaterialProduct {
    id: number;
    title: string;
    image_url: string | null;
    inventory: number;
    /** Index rows only (the product search does not send them). */
    variants?: { id: number; title: string | null; inventory: number }[];
}

export interface MaterialLinkedAd {
    id: number;
    name: string;
    platform: string;
    status: string | null;
}

/** MaterialService::rows. Link arrays may be null for rows written before normalisation: read them through `links()`. */
export interface MaterialRow {
    id: number;
    title: string;
    created_at: string | null;
    thumb_url: string | null;
    files_count: number;
    files?: MaterialFile[];
    product: MaterialProduct | null;
    collections: AdsOption[];
    types: string[];
    status: MaterialStatus;
    drive_links: string[] | null;
    website_links: string[] | null;
    ig_links: string[] | null;
    content_notes: string | null;
    buyer: AdsOption | null;
    creator: AdsOption | null;
    stock: MaterialStock;
    need_stop: boolean;
    activated_at: string | null;
    done_at: string | null;
    ads: MaterialLinkedAd[];
    performance: MaterialPerformance | null;
    /** Open launches of the material (S1), newest first, max 5. */
    launches: MaterialLaunchSummary[];
}

/** A Laravel LengthAwarePaginator as Inertia serialises it. */
export interface LaravelPage<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface MaterialFilters {
    q: string | null;
    status: MaterialStatus | null;
    stock: MaterialStock | null;
    collection: string | null;
    type: MaterialType | null;
    from: string | null;
    to: string | null;
    page: number;
}

export interface MaterialStats {
    total: number;
    activated: number;
    not_started: number;
    done: number;
    in_review: number;
    paused: number;
    reels: number;
    posts: number;
    carousels: number;
    in_stock: number;
    out_of_stock: number;
    need_stop: number;
}

export interface AdsMaterialsIndexProps {
    filters: MaterialFilters;
    stats: MaterialStats;
    materials: LaravelPage<MaterialRow>;
    collections: AdsOption[];
    canSeeSpend: boolean;
}

export interface MaterialFormRow extends MaterialRow {
    files: MaterialFile[];
}

export interface AdsMaterialFormProps {
    material: MaterialFormRow | null;
    collections: AdsOption[];
    types: MaterialType[];
    buyers: AdsOption[];
    limits?: { image_mb: number; video_mb: number; post_mb?: number | null };
}

export interface MaterialCollectionRow {
    id: number;
    name: string;
    is_active: boolean;
    materials: number;
    activated: number;
    not_started: number;
    need_stop: number;
    done: number;
}

export interface AdsMaterialCollectionsProps {
    collections: MaterialCollectionRow[];
    totals: { collections: number; materials: number; activated: number; not_started: number; need_stop: number; done: number };
}

export interface AdStockRow {
    material_id: number;
    title: string;
    thumb_url: string | null;
    product: { id: number; title: string };
    variants: { id: number; title: string | null; sku: string | null; price: number; quantity: number }[];
    price: { min: number | null; max: number | null };
    quantity: number;
    collections: AdsOption[];
    /** Effective availability (the override when set, else inventory > 0). */
    availability: boolean;
    /** The manual pin, or null when it follows the inventory. */
    override: boolean | null;
}

export interface AdsStockProps {
    filters: { min_qty: number | null; availability: 'all' | 'in' | 'out' };
    rows: LaravelPage<AdStockRow>;
}

/** GET /ads/materials/ad-search */
export interface MaterialAdSearchRow {
    id: number;
    name: string;
    external_id: string;
    platform: string;
    account: string | null;
    status: string | null;
    thumbnail_url: string | null;
}

/** StopAdvisor::suggest (+ can_write from ActionController) */
export interface AdSuggestion {
    ad_id: number;
    external_id: string;
    account_id: number;
    account: string;
    platform: AdPlatformValue | string;
    name: string;
    spend: number;
    spend_tax: number;
    roas: number | null;
    reasons: AdReason[];
    can_write: boolean;
    objective: AdObjective;
    thumbnail_url: string | null;
    campaign: string | null;
    status: string | null;
    /** Pre-tax spend today (Cairo), shown in the Stop dialog. */
    spend_today: number;
}

/** One ad_write_actions row on the log (slice-1 rows are copied in as legacy rows). */
export interface AdActionLogRow {
    id: number;
    at: string | null;
    user: string | null;
    platform: AdPlatformValue | string;
    account: string;
    level: 'campaign' | 'adset' | 'ad';
    name: string;
    from_status: string | null;
    to_status: string;
    reason: string | null;
    result: 'ok' | 'error' | 'pending';
    error: string | null;
    ad_id: number | null;
    user_id: number | null;
    account_id: number;
    external_id: string;
    source: string;
}

export interface AdsActionsProps extends AdsBannerProps {
    currency: string;
    days: number;
    suggestions: AdSuggestion[];
    log: AdActionLogRow[];
}

/* ---- Publish a material as paused ads ---- */
export interface PublishAccount {
    id: number;
    name: string;
    platform: AdPlatformValue;
}
export interface PublishAdSet {
    id: string;
    name: string;
    status: string | null;
    naming_ok: boolean;
}
export interface PublishCampaign {
    id: string;
    name: string;
    status: string | null;
    objective: string | null;
    naming_ok: boolean;
    adsets: PublishAdSet[];
}
export interface PublishIdentity {
    page_id: string;
    page_name: string;
    instagram_id: string | null;
}
export interface MaterialCaption {
    id: number;
    file_id: number | null;
    position: number;
    angle: 'emotional' | 'offer' | 'quality';
    headline: string;
    primary_text: string;
    cta: string;
    edited: boolean;
    model: string | null;
}
export interface PublishCaption {
    headline: string;
    primary_text: string;
    cta: string;
}
export type PublicationStatus = 'queued' | 'uploading' | 'processing' | 'creating' | 'done' | 'error';
export interface AdPublicationRow {
    id: number;
    ad_name: string;
    status: PublicationStatus;
    error: string | null;
    platform: AdPlatformValue;
    account: string | null;
    campaign: string | null;
    adset: string | null;
    headline: string;
    external_ad_id: string | null;
    manager_url: string | null;
    created_at: string | null;
}

/* ---- Launch approvals (control room S1) ---- */

export type LaunchState =
    | 'draft'
    | 'changes_requested'
    | 'buyer_review'
    | 'creating_paused'
    | 'create_failed'
    | 'awaiting_approval'
    | 'on_hold'
    | 'launching'
    | 'live'
    | 'stopped'
    | 'retired'
    | 'rejected'
    | 'expired'
    | 'withdrawn';
export type CheckLevel = 'pass' | 'warn' | 'block';
export interface CheckRow {
    key: string;
    level: CheckLevel;
    message_ar: string;
    message_en: string;
    details: Record<string, unknown>;
}
export interface LaunchCaption {
    headline: string;
    primary_text: string;
    cta: string;
}
export interface LaunchFile extends MaterialFile {
    width: number | null;
    height: number | null;
}
export interface LaunchPublication {
    id: number;
    ad_name: string;
    status: string;
    error: string | null;
    external_ad_id: string | null;
    archived: boolean;
    ad_status: string | null;
    effective_status: string | null;
    preview_url: string | null;
    manager_url: string | null;
}
export interface LaunchEvent {
    action: string;
    at: string | null;
    actor: string | null;
    code: string | null;
    reason: string | null;
}
export interface LaunchAbilities {
    edit: boolean;
    submit: boolean;
    send_back: boolean;
    forward: boolean;
    withdraw: boolean;
    retry: boolean;
    approve: boolean;
    return: boolean;
    reject: boolean;
    stop: boolean;
    retire: boolean;
}
export interface LaunchRow {
    id: string;
    state: LaunchState;
    hold_from: LaunchState | null;
    revision: number;
    ads_count: number;
    material: { id: number; title: string; thumb_url: string | null } | null;
    product: { id: number; title: string; image_url: string | null; prices: number[]; inventory: number } | null;
    account: { id: number; name: string; platform: string } | null;
    campaign: { external_id: string | null; name: string | null; status: string | null; objective: string | null };
    adset: { id: number | null; external_id: string | null; name: string | null; status: string | null };
    identity: { page_id: string; page_name: string; instagram_id: string | null } | null;
    link: string | null;
    url_tags: string | null;
    file_ids: number[];
    files: LaunchFile[];
    captions: LaunchCaption[];
    original: { ad_set_id: number | null; file_ids: number[]; captions: LaunchCaption[] } | null;
    people: { preparer: AdsOption | null; buyer: AdsOption | null; forwarder: AdsOption | null; decider: AdsOption | null };
    dates: {
        created_at: string | null;
        submitted_at: string | null;
        forwarded_at: string | null;
        awaiting_at: string | null;
        expires_at: string | null;
        decided_at: string | null;
        live_at: string | null;
        stopped_at: string | null;
    };
    decision: { code: string; reason: string | null } | null;
    last_error: string | null;
    self_approved: boolean;
    checks: CheckRow[] | null;
    checks_hash: string | null;
    publications: LaunchPublication[] | null;
    history: LaunchEvent[] | null;
    money: { cap: number; currency: string; parent_status: string | null; campaign_status: string | null } | null;
    can: LaunchAbilities;
}
export interface OpenSlot {
    id: number;
    name: string;
    external_id: string;
    campaign: { external_id: string; name: string; objective: string | null };
    account: { id: number; name: string; platform: string };
    buyer: AdsOption;
}
export interface SlotRow {
    id: number;
    name: string;
    status: string | null;
    open: boolean;
    opened_at: string | null;
    campaign: { name: string; status: string | null };
    account: AdsOption;
}
export interface LaunchOptions {
    slots: OpenSlot[];
    files: LaunchFile[];
    captions: (LaunchCaption & { angle: string | null })[];
    ctas: string[];
    max_captions: number;
}
export interface MaterialLaunchSummary {
    id: string;
    state: LaunchState;
    account: string | null;
    adset: string | null;
    ads_count: number;
}
export interface LaunchAnswer {
    ok: boolean;
    message: string;
    launch: LaunchRow;
}
export interface AdsLaunchesProps {
    box: 'mine' | 'review' | 'live' | 'all';
    filters: { material: number | null; launch: string | null; stop: boolean; tab?: 'slots' | null };
    launches: LaravelPage<LaunchRow>;
    counts: { mine: number; review: number; live: number };
    canReview: boolean;
    canToggleSlots: boolean;
    reasons: string[];
}
export interface ApprovalFilters {
    buyer: string | null;
    account: string | null;
    age: '1d' | '3d' | '7d' | null;
    fails: boolean;
    expiring: boolean;
    launch: string | null;
}
export interface AdsApprovalsProps {
    filters: ApprovalFilters;
    launches: LaunchRow[];
    options: { buyers: AdsOption[]; accounts: AdsOption[] };
    approvalsLeft: number;
    writesOn: boolean;
    canApprove: boolean;
    isAdmin: boolean;
    reasons: string[];
}
export interface ApproveResult {
    ok: boolean;
    message: string;
    self_approved: boolean;
    launch: { id: string; state: LaunchState; revision: number };
    ads: { publication_id: number; ad_name: string; outcome: 'succeeded' | 'unknown' | 'failed'; code: string | null; message: string | null }[];
}
export interface BulkPlan {
    launches: { id: string; title: string | null; account: string | null; ads: number; revision: number; checks_hash: string }[];
    total_ads: number;
    approvals_left: number;
    skipped: { warned: number; first_launch: number; self: number; limit: number };
}
/* ---- S2 control room ---- */
export type AdObjective = 'messages' | 'sales' | 'traffic' | 'other';
export type AdHealthKey = 'out_of_stock' | 'losing' | 'tired' | 'too_early' | 'parent_paused' | 'winning';
export type AdsRangeKey = 'today' | 'yesterday' | 'last7' | 'last30' | 'this_month';
export type AdsStatusFilter = 'running' | 'paused' | 'all';
/** `top` = winner + promising, `promising` / `neutral` = the old Winners tier chips (kept for redirected links). */
export type AdsHealthFilter = 'no_result' | 'losing' | 'tired' | 'winning' | 'out_of_stock' | 'top' | 'promising' | 'neutral';
export type AdsView = 'table' | 'cards' | 'tree';
export type AdLevel = 'campaign' | 'adset' | 'ad';

export interface AdSeriesPoint {
    date: string;
    spend: number;
    roas: number | null;
}

/** RunningCreatives::rows + AdRowEnricher::enrich */
export interface AdRowData extends CreativeRow {
    objective: AdObjective;
    conversations: number;
    real_revenue: number;
    /** null on non-EGP accounts: store revenue is EGP, so the ratio would mean nothing (render «—»). */
    real_roas: number | null;
    /** The ad account currency (upper-case); money on the row is in it. */
    currency: string;
    spend_today: number;
    need_stop: boolean;
    tier: WinnerTier | null;
    health: AdHealthKey[];
    series: AdSeriesPoint[];
    can_write: boolean;
}

export interface AdsControlFilters extends AdsFilters {
    range: AdsRangeKey | null;
    accounts: number[];
}

export interface AdsAccountOption {
    id: number;
    name: string;
    platform: AdPlatformValue | string;
}

export interface AdsPageBase extends AdsCommonProps {
    account_options: AdsAccountOption[];
    freshness: string | null;
}

/** SpendByHour::today */
export interface AdsSpendByHour {
    spend_so_far: number;
    usual_by_now: number | null;
    ratio: number | null;
    baseline: 'snapshots' | 'prorated' | 'none';
    hour: number;
    hours: { hour: number; today: number; usual: number | null }[];
}

export interface AdsTodayData {
    /** total = the one open-decisions count (DecisionCounter): approvals + open alert cards + unfolded suggestions. */
    decisions: { total: number; approvals: number; suggestions: AdSuggestion[]; suggestions_total: number; alerts: AlertCardData[]; alerts_total: number };
    money_today: AdsSpendByHour & { conversations: number; orders: number };
    last7: { from: string; to: string; totals: AdsTotals; daily: AdsDailyRow[] };
    buyers: (BuyerCardData & { open_decisions: number })[] | null;
    best: AdRowData[];
    worst: AdRowData[];
}

export interface AdsTodayProps extends AdsPageBase {
    filters: AdsControlFilters;
    today: AdsTodayData;
}

export interface AdsExplorerFilters extends AdsControlFilters {
    view: AdsView;
    status: AdsStatusFilter;
    objective: Exclude<AdObjective, 'other'> | null;
    health: AdsHealthFilter | null;
    changed: 'today' | null;
    q: string | null;
    sort: string;
    per_page: 25 | 50 | 100;
    page: number;
}

export interface AdsExplorerResult {
    data: AdRowData[];
    meta: { total: number; per_page: number; current_page: number; last_page: number };
    counts: { all: number; active: number; inactive: number };
    /** Over every matched ad (not just the page); real_roas null when an account is not EGP. */
    totals: AdsDerived & { real_orders: number; real_revenue: number; real_roas: number | null };
}

export interface AdsExplorerProps extends AdsPageBase {
    filters: AdsExplorerFilters;
    result: AdsExplorerResult | null;
    tree: CampaignNode[] | null;
    /** view=cards only: scored ads per tier (`top` = winner + promising). */
    tier_counts: Record<WinnerTierFilter, number> | null;
}

export type DecisionsTab = 'open' | 'snoozed' | 'closed' | 'log';

export interface AdsDecisionsProps extends AdsPageBase {
    filters: AdsControlFilters & { tab: DecisionsTab; who: number | null; level: AdLevel | null; result: 'ok' | 'error' | 'pending' | null };
    counts: { open: number; snoozed: number; closed: number };
    approvals: { count: number; href: string; items: unknown[] } | null;
    suggestions: AdSuggestion[];
    /** S5 alert cards of the feed tab that follows the page tab (snoozed = the feed's «later»). */
    alerts: AlertCardData[];
    alertsMeta: AlertsMeta;
    log: AdActionLogRow[];
    log_users: AdsOption[];
}

export interface AdsChatRow {
    campaign: string;
    ads: string[];
    conversations: number;
    customers: number;
    orders: number;
    revenue: number;
    spend: number | null;
    cost_per_conversation: number | null;
    cost_per_order: number | null;
    roas: number | null;
}

export interface AdsChatReport {
    rows: AdsChatRow[];
    totals: { conversations: number; customers: number; orders: number; revenue: number; spend: number | null; roas: number | null; cost_per_order: number | null };
    currency: string | null;
    spend_available: boolean;
}

/** `buyers` here is the buyer cards (it replaces the common buyer options; the filter bar derives its options from the cards). */
export interface AdsNumbersProps extends Omit<AdsPageBase, 'buyers'> {
    filters: AdsControlFilters & { section: 'buyers' | 'chat' | 'accounts' | null };
    sync_errors: { account: string; error: string }[];
    sync: AdsSync;
    overview: AdsOverviewData;
    summary: AdsRevenueSummary;
    top_accounts: AdsTopAccountRow[];
    buyers: BuyerCardData[];
    chat_campaigns: AdsChatReport | null;
    /** S3: deferred (group `funnel`), totals over the page filter. */
    chatFunnel?: ChatFunnel;
}

/* Control room S3: the per-ad chat funnel (GET /ads/chat-funnel). */
export type LostReason = 'price' | 'size_out' | 'shipping' | 'no_answer' | 'browsing' | 'other';
export interface ChatFunnel {
    chats: number;
    to_agent: number;
    orders: number;
    delivered: number;
    returned: number;
    reasons: Partial<Record<LostReason, number>>;
}

/** GET /ads/ad/{id} */
export interface AdDrawerData {
    ad: AdRowData & { preview_html: string | null };
    reasons: AdReason[];
    decisions: { kind: 'stop_suggestion'; reasons: AdReason[] }[];
    history: AdActionLogRow[];
    funnel: ChatFunnel | null;
    levels: AdLevel[];
}

export interface BreakEvenInputs {
    margin_pct: number | null;
    shipping_subsidy: number | null;
    return_cost: number | null;
    target_cpp: number | null;
    target_cpo: number | null;
}

export interface BreakEvenExplain {
    floor: number;
    is_default: boolean;
    unprofitable: boolean;
    margin_pct: number | null;
    shipping_subsidy: number;
    return_cost: number;
    aov: number | null;
    aov_source: 'account' | 'store' | 'none';
    refusal_rate: number;
    refusal_source: 'account' | 'store' | 'assumed';
    max_cpa: number | null;
    tax_rate: number;
}

export interface RulesSetupAccount {
    id: number;
    name: string;
    currency: string | null;
    platform: string;
    inputs: BreakEvenInputs;
    effective: BreakEvenExplain;
    targets: { cpp: number | null; cpp_source: 'owner' | 'median' | 'none'; cpo: number | null; cpo_source: 'owner' | 'median' | 'none'; cpc: number | null };
}

export interface RulesSetupProps {
    global: BreakEvenInputs;
    general: { low_stock_units: number; spike_min_amount: number };
    notify_enabled: boolean;
    can_edit: boolean;
    default_floor: number;
    accounts: RulesSetupAccount[];
}

/* S5 decisions feed (spec 7.3-7.5, U 5.2-5.3). */
export type AlertSeverity = 'critical' | 'high' | 'medium' | 'info';
export type AlertVerb =
    | 'stop'
    | 'run'
    | 'open_stock'
    | 'open_campaigns'
    | 'open_library'
    | 'open_queue'
    | 'open_settings'
    | 'add_replacement'
    | 'view_orders'
    | 'why';
export type AlertParams = Record<string, string | number | boolean | null>;

export interface AlertReason {
    alert_id: number;
    rule_id: string;
    severity: AlertSeverity;
    action: string;
    sentence_key: string;
    params: AlertParams;
    evidence: Record<string, unknown>;
    first_fired_at: string | null;
    seen: boolean;
    state: 'open' | 'snoozed' | 'dismissed' | 'resolved' | 'acted';
    snoozed_until: string | null;
    closed_at: string | null;
    closed_by: string | null;
    resolved_reason: string | null;
    dismiss_reason: string | null;
    can_dismiss: boolean;
}

export interface AlertAdRef {
    id: number;
    external_id: string;
    name: string;
    thumbnail_url: string | null;
    account_id: number;
    account: string;
    buyer: string | null;
    can_write: boolean;
}

export interface AlertCardData {
    key: string;
    kind: 'ad' | 'product' | 'account';
    severity: AlertSeverity;
    money_at_risk_per_day: number;
    first_fired_at: string | null;
    ad: AlertAdRef | null;
    product: { id: number; title: string } | null;
    account: { id: number; name: string } | null;
    ads: (AlertAdRef & { alert_id: number; action: string })[];
    reasons: AlertReason[];
    primary: { verb: AlertVerb; action: string; alert_id: number; href: string | null };
    alert_ids: number[];
}

export type AlertsFeedTab = 'open' | 'later' | 'closed' | 'log';

export interface AlertsMeta {
    tab: AlertsFeedTab;
    counts: { open: number; later: number; closed: number };
    hidden_by_cap: number;
    buyer_cap: number;
    shadow: boolean;
    can_toggle: boolean;
    can_open_settings: boolean;
}

export interface DigestItem {
    alert_id: number;
    rule_id: string;
    severity: AlertSeverity;
    sentence_key: string;
    params: AlertParams;
    ad: string | null;
    account: string | null;
    buyer: string | null;
    money: number;
    age_days: number;
}

export interface DigestBuyerRow {
    buyer_id: number;
    name: string;
    open: number;
    acted: number;
    dismissed: number;
    dismissed_wrong_numbers: number;
    silent_spend: number;
}

export interface DigestData {
    variant: 'owner' | 'buyer';
    date: string;
    shadow: boolean;
    currency: string;
    yesterday: {
        spend: number;
        spend_tax: number;
        orders: number;
        revenue: number;
        roas: number | null;
        meta_roas: number | null;
        usual_spend: number | null;
        floor: number;
        floor_default: boolean;
    };
    open: { count: number; critical: number; high: number; money: number };
    top: DigestItem[];
    winners: DigestItem[];
    stock: { ads: number; products: string[] };
    inbox: Record<string, string | number> | null;
    stale_accounts: number;
    approvals: number;
    review_waiting: number;
    buyers: DigestBuyerRow[];
    stopped_yesterday: number;
}
