import { formatNumber, translate, type Locale } from '@/i18n';

export const DISPLAY_TIMEZONE = 'Africa/Cairo';

const intlLocale = (locale: Locale) => (locale === 'ar' ? 'ar-EG' : 'en-GB');

function toDate(iso: string | null | undefined): Date | null {
    if (!iso) {
        return null;
    }
    const d = new Date(iso);

    return Number.isNaN(d.getTime()) ? null : d;
}

/** "14:05" in Cairo. */
export function formatClock(iso: string | null | undefined, locale: Locale): string {
    const d = toDate(iso);

    return d ? new Intl.DateTimeFormat(intlLocale(locale), { hour: '2-digit', minute: '2-digit', timeZone: DISPLAY_TIMEZONE }).format(d) : '';
}

/** "12 Sep, 14:05" in Cairo. */
export function formatDateTime(iso: string | null | undefined, locale: Locale): string {
    const d = toDate(iso);

    return d
        ? new Intl.DateTimeFormat(intlLocale(locale), {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
              timeZone: DISPLAY_TIMEZONE,
          }).format(d)
        : '';
}

/** Cairo calendar day ("2026-09-12") used to group thread messages. */
export function cairoDayKey(iso: string | null | undefined): string {
    const d = toDate(iso) ?? new Date();

    return new Intl.DateTimeFormat('en-CA', { timeZone: DISPLAY_TIMEZONE }).format(d);
}

/** Day divider label: "السبت ١٢ سبتمبر". */
export function formatDay(iso: string | null | undefined, locale: Locale): string {
    const d = toDate(iso) ?? new Date();

    return new Intl.DateTimeFormat(intlLocale(locale), { weekday: 'long', day: 'numeric', month: 'long', timeZone: DISPLAY_TIMEZONE }).format(d);
}

/** Short list stamp: clock for today (Cairo), otherwise day + month. */
export function formatListStamp(iso: string | null | undefined, locale: Locale, now: number): string {
    const d = toDate(iso);

    if (!d) {
        return '';
    }

    const day = (x: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: DISPLAY_TIMEZONE }).format(x);

    if (day(d) === day(new Date(now))) {
        return formatClock(iso, locale);
    }

    return new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', timeZone: DISPLAY_TIMEZONE }).format(d);
}

/** Compact relative duration: "٥ د" / "3h" / "2d". Internal to `formatSince`/`formatUntil`. */
function relativeDuration(ms: number, locale: Locale): string {
    const minutes = Math.max(0, Math.floor(ms / 60000));

    if (minutes < 1) {
        return translate(locale, 'time.now');
    }
    if (minutes < 60) {
        return translate(locale, 'time.minutes', { n: minutes });
    }
    if (minutes < 60 * 24) {
        return translate(locale, 'time.hours', { n: Math.floor(minutes / 60) });
    }

    return translate(locale, 'time.days', { n: Math.floor(minutes / 1440) });
}

/** "منذ ٥ د" / "5m ago". */
export function formatSince(iso: string | null | undefined, locale: Locale, now: number): string {
    const d = toDate(iso);

    if (!d) {
        return '';
    }
    const duration = relativeDuration(now - d.getTime(), locale);

    return now - d.getTime() < 60000 ? duration : translate(locale, 'time.ago', { time: duration });
}

/** Remaining time until an ISO instant, or null when past. */
export function formatUntil(iso: string | null | undefined, locale: Locale, now: number): string | null {
    const d = toDate(iso);

    if (!d || d.getTime() <= now) {
        return null;
    }

    return relativeDuration(d.getTime() - now, locale);
}

/** File size: "١٫٥ م.ب" / "1.5 MB". */
export function formatBytes(bytes: number | null, locale: Locale): string {
    if (bytes === null) return '';
    const units = locale === 'ar' ? ['بايت', 'ك.ب', 'م.ب'] : ['B', 'KB', 'MB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${formatNumber(locale, value, { maximumFractionDigits: unit === 0 ? 0 : 1 })} ${units[unit]}`;
}

/** Media duration as "m:ss" (audio/video player clock), Arabic-Indic digits in ar. */
export function formatDuration(ms: number | null, locale: Locale): string {
    const total = Math.max(0, Math.round((ms ?? 0) / 1000));
    const mm = Math.floor(total / 60);
    const ss = String(total % 60).padStart(2, '0');
    return locale === 'ar' ? `${formatNumber(locale, mm)}:${ss.replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[Number(d)])}` : `${mm}:${ss}`;
}

/** EGP with exactly two decimals ("١٬٢٥٠٫٠٠ ج.م" / "1,250.00 EGP"). */
export function formatMoney(amount: number | string | null | undefined, locale: Locale): string {
    const value = Number(amount ?? 0);

    return `${formatNumber(locale, value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${translate(locale, 'common.currency')}`;
}

/** Elapsed run time as "8m 55s" / "٨ د ٥٥ ث" — units and digits both follow the locale. */
export function formatShortDuration(seconds: number | null | undefined, locale: Locale): string {
    const total = Math.max(0, Math.round(Number(seconds ?? 0)));
    const m = Math.floor(total / 60);

    return m > 0 ? translate(locale, 'time.minutes_seconds', { m, s: total % 60 }) : translate(locale, 'time.seconds', { n: total });
}

/** Locale digits for a count. */
export function formatCount(value: number | string | null | undefined, locale: Locale): string {
    return formatNumber(locale, Number(value ?? 0));
}

/** Seconds as "m:ss", or "h:mm:ss" from one hour. Digits follow the locale. */
export function formatSeconds(total: number | null | undefined, locale: Locale): string {
    const seconds = Math.max(0, Math.round(Number(total ?? 0)));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const two = (n: number) => formatNumber(locale, n, { minimumIntegerDigits: 2, useGrouping: false });
    const one = (n: number) => formatNumber(locale, n, { useGrouping: false });

    return h > 0 ? `${one(h)}:${two(m)}:${two(s)}` : `${one(m)}:${two(s)}`;
}

/** Minutes as "h:mm" (online time). */
export function formatMinutes(total: number | null | undefined, locale: Locale): string {
    const minutes = Math.max(0, Math.round(Number(total ?? 0)));
    const two = (n: number) => formatNumber(locale, n, { minimumIntegerDigits: 2, useGrouping: false });

    return `${formatNumber(locale, Math.floor(minutes / 60), { useGrouping: false })}:${two(minutes % 60)}`;
}

/** "12 Sep 2026" in Cairo. */
export function formatDate(iso: string | null | undefined, locale: Locale): string {
    const d = toDate(iso);

    return d ? new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', year: 'numeric', timeZone: DISPLAY_TIMEZONE }).format(d) : '';
}

/** Today's Cairo calendar date as "YYYY-MM-DD". */
export function cairoToday(): string {
    return cairoDayKey(new Date().toISOString());
}

/** Adds whole days to a "YYYY-MM-DD" date (calendar arithmetic, timezone-free). */
export function addDays(ymd: string, days: number): string {
    const [y, m, d] = ymd.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + days));

    return date.toISOString().slice(0, 10);
}
