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
    isBuyer: boolean;
    buyerId: number | null;
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

export interface AdsTotals extends AdsDerived {
    real_orders: number;
    real_revenue: number;
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

export interface AdsSync {
    last_synced_at: string | null;
    errors: { account: string; error: string }[];
}

/** BuildsAdsPages::commonProps — on every report page. `buyers` is empty for media buyers. */
export interface AdsCommonProps {
    buyers: AdsOption[];
    platforms: AdPlatformValue[];
    currency: string;
}

export interface AdsOverviewProps extends AdsCommonProps {
    filters: AdsFilters;
    overview: AdsOverviewData;
    sync: AdsSync;
}

/** BuyerScorecard::card — buyer_id null is the «unassigned» card. */
export interface BuyerCardData {
    buyer_id: number | null;
    name: string;
    color: string | null;
    accounts: { id: number; name: string; platform: AdPlatformValue }[];
    spend: number;
    spend_tax: number;
    purchase_value: number;
    roas: number | null;
    purchases: number;
    cpa: number | null;
    ctr: number | null;
    real_orders: number;
    real_revenue: number;
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
    filters: AdsFilters;
    buyer: { id: number; name: string; color: string | null };
    detail: BuyerDetail;
}

/** RunningCreatives::rows */
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
}

/** GET /ads/creatives/{ad} (RunningCreatives::detail) */
export interface CreativeDetail extends CreativeRow {
    preview_html: string | null;
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
}

export interface WinnersFilters extends AdsFilters {
    status: CreativeStatusFilter;
    sort: WinnerSort;
}

export interface AdsWinnersProps extends AdsCommonProps {
    filters: WinnersFilters;
    window: { from: string; to: string };
    winners: WinnerRow[];
}
