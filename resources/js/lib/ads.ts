import { formatNumber, translate, type Locale } from '@/i18n';
import type { AdPlatformValue, AdReason, AdsFilters, CreativeRow } from '@/types/ads';
import { router } from '@inertiajs/vue3';

export type AdsQueryValue = string | number | string[] | null | undefined;

/** Brand colours of the ad platforms (chips, platform dots). */
export const AD_PLATFORM_COLORS: Record<AdPlatformValue, string> = {
    meta: '#1877F2',
    tiktok: '#FE2C55',
    google: '#34A853',
};

export const AD_PLATFORM_LABELS: Record<AdPlatformValue, string> = { meta: 'Meta', tiktok: 'TikTok', google: 'Google' };

export const AD_PLATFORMS: AdPlatformValue[] = ['meta', 'tiktok', 'google'];

/** The filter part every Ads page sends back: range, platform, buyer. */
export function baseQuery(filters: AdsFilters): Record<string, AdsQueryValue> {
    return { from: filters.from, to: filters.to, platform: filters.platform, buyer: filters.buyer };
}

/**
 * Inertia visit to the current Ads page with the given params. Empty values are dropped; an
 * `accounts[]` filter already in the URL is carried over as an array (AdsFilter reads `accounts`).
 */
export function visitAds(params: Record<string, AdsQueryValue>, path: string = window.location.pathname): void {
    const query: Record<string, string | number | string[]> = {};
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') query[key] = value;
    }
    const accounts = new URLSearchParams(window.location.search).getAll('accounts[]');
    if (accounts.length && query.accounts === undefined) query.accounts = accounts;

    router.get(path, query, { preserveState: true, preserveScroll: true, replace: true });
}

/** Query string for links/fetches that keep the range (e.g. the creative JSON, buyer pages). */
export function rangeQueryString(filters: AdsFilters): string {
    const q = new URLSearchParams();
    for (const [key, value] of Object.entries(baseQuery(filters))) {
        if (value !== null && value !== undefined && value !== '') q.set(key, String(value));
    }
    for (const id of new URLSearchParams(window.location.search).getAll('accounts[]')) q.append('accounts[]', id);
    const s = q.toString();

    return s ? `?${s}` : '';
}

/** Money: the local currency label for EGP, the ISO code otherwise. */
export function formatAdsMoney(value: number | null | undefined, locale: Locale, currency = 'EGP', digits = 0): string {
    if (value === null || value === undefined || Number.isNaN(value)) return '—';
    // Several currencies in scope (AdsOverview::MIXED): no amount is meaningful, never print "1,234 mixed".
    if (currency === 'mixed') return '—';
    const unit = currency === 'EGP' ? translate(locale, 'common.currency') : currency;

    return `${formatNumber(locale, value, { maximumFractionDigits: digits, minimumFractionDigits: 0 })} ${unit}`;
}

/** ROAS / multiplier: "3.25×". */
export function formatRoas(value: number | null | undefined, locale: Locale): string {
    if (value === null || value === undefined) return '—';

    return `${formatNumber(locale, value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}×`;
}

/** A fraction (0.0123) as a percent ("1.23%"). */
export function formatPct(fraction: number | null | undefined, locale: Locale, digits = 2): string {
    if (fraction === null || fraction === undefined) return '—';

    return formatNumber(locale, fraction, { style: 'percent', minimumFractionDigits: digits, maximumFractionDigits: digits });
}

/** Counts that may be fractional on the platform side (purchases): up to 2 decimals. */
export function formatQty(value: number | null | undefined, locale: Locale): string {
    if (value === null || value === undefined) return '—';

    return formatNumber(locale, value, { maximumFractionDigits: 2 });
}

/** Compact axis label: "12K". */
export function formatCompact(value: number, locale: Locale): string {
    return formatNumber(locale, value, { notation: 'compact', maximumFractionDigits: 1 });
}

/** "2026-09-14" → "14 Sep" (calendar day, no timezone shift). */
export function formatDayShort(ymd: string, locale: Locale): string {
    const [y, m, d] = ymd.split('-').map(Number);
    if (!y || !m || !d) return ymd;

    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
        new Date(Date.UTC(y, m - 1, d)),
    );
}

/** "2026-09-14" → "14 Sep 2026". */
export function formatDayLong(ymd: string | null | undefined, locale: Locale): string {
    if (!ymd) return '—';
    const [y, m, d] = ymd.slice(0, 10).split('-').map(Number);
    if (!y || !m || !d) return ymd;

    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(y, m - 1, d)),
    );
}

/** ROAS colour band (owner's Arena bands): ≥ 5 green, ≥ 3 amber, else red. */
export function roasTone(roas: number | null | undefined): 'positive' | 'warning' | 'negative' | 'neutral' {
    if (roas === null || roas === undefined) return 'neutral';
    if (roas >= 5) return 'positive';
    if (roas >= 3) return 'warning';

    return 'negative';
}

export function isAdActive(ad: Pick<CreativeRow, 'effective_status'>): boolean {
    return ad.effective_status === 'ACTIVE';
}

/** Facebook post embed for an object_story_id "pageId_postId" (null when it is not that shape). */
export function fbPostEmbedUrl(objectStoryId: string | null | undefined): string | null {
    if (!objectStoryId) return null;
    const [pageId, postId] = objectStoryId.split('_');
    if (!pageId || !postId || !/^\d+$/.test(pageId) || !/^\d+$/.test(postId)) return null;
    const href = `https://www.facebook.com/${pageId}/posts/${postId}`;

    return `https://www.facebook.com/plugins/post.php?href=${encodeURIComponent(href)}&show_text=true&width=500`;
}

/** Only http(s) links are ever bound to href/src (platform data is untrusted). */
export function safeUrl(url: string | null | undefined): string | null {
    if (!url) return null;

    return /^https?:\/\//i.test(url) ? url : null;
}

/** Ad type label key: the drivers store image / video / carousel. */
export function adTypeKey(type: string | null | undefined): string {
    const t = (type ?? '').toLowerCase();

    return ['image', 'video', 'carousel'].includes(t) ? t : 'other';
}

/** ISO instant → Cairo "14 Sep" (thumbnail date pill). */
export function formatIsoDayShort(iso: string | null | undefined, locale: Locale): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';

    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'short', timeZone: 'Africa/Cairo' }).format(d);
}

/** "conversations → ordered" arrow that points forward in the reading direction. */
export function flowArrow(locale: Locale): string {
    return locale === 'ar' ? '←' : '→';
}

const PREVIEW_HOSTS = ['facebook.com', 'fb.com'];

/** Preview frames may only load https pages on facebook.com / fb.com (or their subdomains). */
export function allowedPreviewUrl(url: string | null | undefined): string | null {
    if (!url) return null;
    try {
        const u = new URL(url);
        const host = u.hostname.toLowerCase();
        if (u.protocol !== 'https:' || u.username || u.password) return null;

        return PREVIEW_HOSTS.some((h) => host === h || host.endsWith(`.${h}`)) ? u.toString() : null;
    } catch {
        return null;
    }
}

/**
 * The platform's preview markup is never rendered: only the src of its first iframe is taken, and only
 * when it passes the host check. The page then loads that URL in a cross-origin iframe.
 */
export function previewSrcFromHtml(html: string | null | undefined): string | null {
    if (!html || typeof DOMParser === 'undefined') return null;
    const doc = new DOMParser().parseFromString(html, 'text/html');

    return allowedPreviewUrl(doc.querySelector('iframe[src]')?.getAttribute('src'));
}

/** Ad account status words as the platforms send them (active, ENABLE, open...). */
export const adAccountActive = (status: string | null | undefined): boolean => (status ? /^(active|enable|enabled|open)$/i.test(status) : false);

/** Platform account status in the UI language; anything unknown stays as the platform sent it. */
export function adAccountStatusLabel(status: string | null | undefined, t: (key: string) => string): string {
    if (!status) return '—';
    if (adAccountActive(status)) return t('ads.accounts.status_active');
    if (/^(disabled|paused|closed|suspended)$/i.test(status)) return t('ads.accounts.status_disabled');
    return status;
}

const RUNNING_STATUSES = ['ACTIVE', 'ENABLE', 'STATUS_ENABLE', 'STATUS_DELIVERY_OK'];
const PAUSED_STATUSES = ['PAUSED', 'DISABLE', 'STATUS_DISABLE'];

/** What a Stop / Run button should do for an item's OWN status (never effective_status): stop a running one, run a paused one, nothing for the rest. */
export function toggleTarget(status: string | null | undefined): 'paused' | 'active' | null {
    if (!status) return null;
    if (RUNNING_STATUSES.includes(status)) return 'paused';
    if (PAUSED_STATUSES.includes(status)) return 'active';

    return null;
}

const BAD_REASONS = ['roas_below', 'recent_down', 'fatigue', 'no_purchases', 'need_stop'];

/** The written reasons (WinnerScorer / StopAdvisor) as plain translated lines, numbers formatted for the locale. */
export function reasonTexts(reasons: AdReason[], locale: Locale, currency = 'EGP'): { key: string; text: string; bad: boolean }[] {
    return reasons.map((r) => {
        const v: Record<string, string | number> = {};
        for (const [k, val] of Object.entries(r.params)) {
            if (typeof val === 'string') {
                v[k] = val;
                continue;
            }
            v[k] =
                k === 'roas' || k === 'threshold'
                    ? formatRoas(val, locale)
                    : k === 'ctr'
                      ? formatPct(val, locale)
                      : k === 'spend' || k === 'cpa'
                        ? formatAdsMoney(val, locale, currency)
                        : k === 'pct' || k === 'ctr_drop'
                          ? formatPct(val / 100, locale, 0)
                          : val;
        }

        return { key: r.key, text: translate(locale, `ads.reasons.${r.key}`, v), bad: BAD_REASONS.includes(r.key) };
    });
}

/** Idempotency key: crypto.randomUUID in a secure context, else time + random (matches [A-Za-z0-9:_-]{8,100}). */
export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}${Math.random().toString(36).slice(2, 12)}`;
}

/** Meta Ads Manager deep link for one ad; null for other platforms. */
export function adsManagerUrl(ad: { platform: string; external_id: string }): string | null {
    return ad.platform === 'meta' ? `https://www.facebook.com/adsmanager/manage/ads?selected_ad_ids=${encodeURIComponent(ad.external_id)}` : null;
}
