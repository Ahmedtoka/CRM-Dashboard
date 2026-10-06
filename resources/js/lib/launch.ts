import type { Locale } from '@/i18n';
import type { CheckRow, LaunchState } from '@/types/ads';

export type ChipTone = 'neutral' | 'positive' | 'warning' | 'negative' | 'info';

/** One tone per launch state: who has to act reads at a glance (amber = someone must decide, red = blocked). */
export const LAUNCH_TONES: Record<LaunchState, ChipTone> = {
    draft: 'neutral',
    changes_requested: 'warning',
    buyer_review: 'info',
    creating_paused: 'info',
    create_failed: 'negative',
    awaiting_approval: 'warning',
    on_hold: 'negative',
    launching: 'info',
    live: 'positive',
    stopped: 'neutral',
    retired: 'neutral',
    rejected: 'negative',
    expired: 'neutral',
    withdrawn: 'neutral',
};

export function checkMessage(c: CheckRow, locale: Locale): string {
    return locale === 'en' ? c.message_en : c.message_ar;
}

export function blockingKeys(checks: CheckRow[] | null | undefined): string[] {
    return (checks ?? []).filter((c) => c.level === 'block').map((c) => c.key);
}

export function warningKeys(checks: CheckRow[] | null | undefined): string[] {
    return (checks ?? []).filter((c) => c.level === 'warn').map((c) => c.key);
}

/** crypto.randomUUID needs a secure context; fall back to a getRandomValues v4 id (same rule as the publish dialog). */
export function newIdempotencyKey(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/**
 * last_error as people read it: comma-separated machine codes go through ads.launch.error.<code> (an unknown code reads
 * "refused"); anything else is the platform's own sentence and is shown as is.
 */
export function launchErrorText(raw: string | null | undefined, t: (key: string, params?: Record<string, string | number>) => string): string {
    if (!raw) return '';
    const parts = raw
        .split(',')
        .map((p) => p.trim())
        .filter(Boolean);
    if (!parts.every((p) => /^[a-z][a-z_]*$/.test(p))) return raw;

    return [
        ...new Set(
            parts.map((code) => {
                const key = `ads.launch.error.${code}`;
                const text = t(key);
                return text === key ? t('ads.launch.error.other') : text;
            }),
        ),
    ].join(' · ');
}
