import { formatNumber, translate, type Locale } from '@/i18n';
import type { AdPlatformValue, AdsFilters, CreativeRow } from '@/types/ads';
import { router } from '@inertiajs/vue3';

export type AdsQueryValue = string | number | null | undefined;

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
