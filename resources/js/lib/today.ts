import { formatNumber, type Locale } from '@/i18n';

// «النهارده» (control room S4) pure helpers. Task 13 completes this file.

/** One decimal (4.4, 3.0); a dash when there is nothing to average. */
export function formatRatio(v: number | null | undefined, locale: Locale): string {
    return v === null || v === undefined ? '—' : formatNumber(locale, v, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}
