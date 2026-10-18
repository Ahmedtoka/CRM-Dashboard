import { formatNumber, type Locale } from '@/i18n';
import { formatAvgSeconds, formatCount, formatMoney, formatSeconds } from '@/lib/format';
import type { Role } from '@/types/crm';
import type { LedgerFigure } from '@/components/today/Ledger.vue';
import type { AdsCard, ChatsCard, OrdersCard, TodayCardsData, UrgentItem, WhyCard } from '@/types/today';

export interface CardRow {
    key: string;
    label: string;
    value: string;
    href?: string | null;
    tone?: 'default' | 'good' | 'bad';
}
export type Translate = (key: string, params?: Record<string, string | number>) => string;

const n = (v: number, locale: Locale) => formatCount(v, locale);

export function formatRatio(v: number | null | undefined, locale: Locale): string {
    return v === null || v === undefined ? '—' : formatNumber(locale, v, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}

export function formatShare(v: number | null | undefined, locale: Locale): string {
    return v === null || v === undefined ? '—' : formatNumber(locale, v, { style: 'percent', maximumFractionDigits: 0 });
}

/** «الثلاثاء ٦ أكتوبر»: the Cairo calendar day, noon UTC so no timezone moves it. */
export function formatTodayDate(ymd: string, locale: Locale): string {
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'Africa/Cairo',
    }).format(new Date(`${ymd}T12:00:00Z`));
}

/**
 * A relative age in words (final review C5): «٤٥ دقيقة» / «٣ ساعات» / «يومين», "45 minutes" / "3 hours". Minutes under
 * an hour, whole hours under two days, then whole days; Arabic plural forms from Intl.PluralRules.
 */
export function formatAge(minutes: number, t: Translate, locale: Locale): string {
    const m = Math.max(1, Math.round(minutes));
    const [unit, value] = m < 60 ? ['minute', m] : m < 48 * 60 ? ['hour', Math.floor(m / 60)] : ['day', Math.floor(m / 1440)];
    const form = new Intl.PluralRules(locale === 'ar' ? 'ar' : 'en').select(value);

    return t(`today.urgent.age.${unit}.${form}`, { n: n(value, locale) });
}

export function urgentText(item: UrgentItem, t: Translate, locale: Locale): { label: string; detail: string | null } {
    const label = t(`today.urgent.${item.key}`, { n: n(item.count, locale) });
    if (item.key === 'lounge' && item.longest_wait_seconds != null)
        return { label, detail: t('today.urgent.lounge_longest', { time: formatSeconds(item.longest_wait_seconds, locale) }) };
    if (item.key === 'ads_sync' && item.age_minutes != null)
        return { label, detail: t('today.urgent.ads_sync_age', { time: formatAge(item.age_minutes, t, locale) }) };

    return { label, detail: null };
}

export function chatsRows(c: ChatsCard, t: Translate, locale: Locale): CardRow[] {
    const rows: CardRow[] = [
        { key: 'new', label: t('today.chats.new'), value: n(c.new, locale), href: c.links.new },
        {
            key: 'from_ads',
            label: t('today.chats.from_ads'),
            value: `${n(c.from_ads, locale)} (${formatShare(c.ads_share, locale)})`,
            href: c.links.ads,
        },
        { key: 'bot_alone', label: t('today.chats.bot_alone'), value: n(c.bot_alone, locale), href: c.links.bot },
        { key: 'to_agent', label: t('today.chats.to_agent'), value: n(c.to_agent, locale), href: c.links.bot },
        {
            key: 'first_reply',
            label: t('today.chats.first_reply'),
            value: formatAvgSeconds(c.first_reply_avg_sec, locale),
            href: c.links.first_reply,
        },
    ];
    if (c.queue) {
        const q = c.queue;
        rows.push(
            {
                key: 'sla',
                label: t('today.chats.sla'),
                value:
                    q.sla_pct === null
                        ? '—'
                        : t('today.chats.sla_value', {
                              pct: formatShare(q.sla_pct / 100, locale),
                              target: formatShare(q.sla_target_pct / 100, locale),
                          }),
                href: c.links.queue,
                tone: q.sla_pct === null ? 'default' : q.sla_pct >= q.sla_target_pct ? 'good' : 'bad',
            },
            { key: 'closed', label: t('today.chats.closed'), value: `${n(q.closed_manual, locale)} / ${n(q.issued, locale)}`, href: c.links.queue },
            {
                key: 'avg_wait',
                label: t('today.chats.avg_wait'),
                value: q.avg_wait_seconds === null ? '—' : formatSeconds(q.avg_wait_seconds, locale),
                href: c.links.queue,
            },
        );
    }
    if (c.rating.count > 0) {
        rows.push({
            key: 'rating',
            label: t('today.chats.rating'),
            value: t('today.chats.rating_value', { avg: formatRatio(c.rating.avg, locale), n: n(c.rating.count, locale) }),
            href: c.links.rating,
            tone: c.rating.low > 0 ? 'bad' : 'default',
        });
    }

    return rows;
}

export function ordersRows(o: OrdersCard, t: Translate, locale: Locale): CardRow[] {
    return [
        { key: 'count', label: t('today.orders.count'), value: `${n(o.count, locale)} · ${formatMoney(o.total, locale)}`, href: o.links.count },
        { key: 'from_chat', label: t('today.orders.from_chat'), value: n(o.from_chat, locale), href: o.links.from_chat },
        { key: 'from_store', label: t('today.orders.from_store'), value: n(o.from_store, locale), href: o.links.from_store },
        { key: 'cancelled', label: t('today.orders.cancelled'), value: n(o.cancelled, locale), href: o.links.cancelled },
        { key: 'failed', label: t('today.orders.failed'), value: n(o.failed, locale), href: o.links.failed, tone: o.failed > 0 ? 'bad' : 'default' },
    ];
}

export function adsRows(a: AdsCard, t: Translate, locale: Locale): CardRow[] {
    const rows: CardRow[] = [
        { key: 'spend', label: t('today.ads.spend'), value: a.spend === null ? '—' : formatMoney(a.spend, locale), href: a.links.spend },
        // D10: real ROAS is the headline, Meta's small beside it.
        {
            key: 'roas',
            label: t('today.ads.real_roas'),
            value: `${formatRatio(a.real_roas, locale)} · ${t('today.ads.meta_roas')} ${formatRatio(a.meta_roas, locale)}`,
            href: a.links.spend,
        },
        { key: 'orders', label: t('today.ads.real_orders'), value: n(a.real_orders, locale), href: a.links.orders },
        {
            key: 'cpo',
            label: t('today.ads.cost_per_order'),
            value: a.cost_per_order === null ? '—' : formatMoney(a.cost_per_order, locale),
            href: a.links.spend,
        },
    ];
    if (a.best)
        rows.push({
            key: 'best',
            label: t('today.ads.best', { name: a.best.name }),
            value: t('today.ads.best_value', { n: n(a.best.orders, locale) }),
            href: a.links.best,
            tone: 'good',
        });
    if (a.loser)
        rows.push({
            key: 'loser',
            label: t('today.ads.loser', { name: a.loser.name }),
            value: formatMoney(a.loser.spend, locale),
            href: a.links.loser,
            tone: 'bad',
        });

    return rows;
}

export function whyRows(w: WhyCard, t: Translate, locale: Locale): CardRow[] {
    const rows: CardRow[] = w.reasons.map((r) => ({
        key: r.key,
        label: t(`today.why.reasons.${r.key}`),
        value: formatShare(r.share, locale),
        href: w.links.reasons,
    }));
    if (w.top_size_out) {
        rows.push({
            key: 'top_size_out',
            label: t('today.why.top_size_out', { name: w.top_size_out.name }),
            value: n(w.top_size_out.count, locale),
            href: w.links.top_size_out,
        });
    }

    return rows;
}

/** The sidebar logo (final review C4): «النهارده» for admins and supervisors, else `/` (HomeRoute picks the home). */
export function logoHref(role: Role | undefined): string {
    return role === 'admin' || role === 'supervisor' ? '/today' : '/';
}


/** The oldest of the blocks' cache stamps (ISO): the page's data is as fresh as its stalest block. */
export function oldestStamp(stamps: (string | null | undefined)[]): string | null {
    let oldest: string | null = null;
    for (const s of stamps) {
        if (!s || Number.isNaN(Date.parse(s))) continue;
        if (oldest === null || Date.parse(s) < Date.parse(oldest)) oldest = s;
    }

    return oldest;
}

/**
 * The day's ledger (DESIGN §3 Ledger, mockup «النهارده › الملخص»): orders, sales, ad spend, real ROAS, new chats and the
 * first human reply, each with one line of context and the link of the screen that lists it. The two ad figures drop
 * out when the viewer has no ad accounts (or the currencies are mixed: no money summed across them).
 */
export function ledgerFigures(c: TodayCardsData, t: Translate, locale: Locale): LedgerFigure[] {
    const o = c.orders;
    const ch = c.chats;
    const money = (v: number) => formatNumber(locale, Math.round(v));
    const currency = t('common.currency');
    const figures: LedgerFigure[] = [
        {
            key: 'orders',
            label: t('today.ledger.orders'),
            value: n(o.count, locale),
            note: t('today.ledger.orders_split', { chat: n(o.from_chat, locale), store: n(o.from_store, locale) }),
            href: o.links.count,
        },
        {
            key: 'sales',
            label: t('today.ledger.sales'),
            value: money(o.total),
            unit: currency,
            note: o.count > 0 ? t('today.ledger.avg_order', { value: money(o.total / o.count) }) : null,
            href: o.links.count,
        },
    ];
    const a = c.ads;
    if (a && a.currency !== 'mixed') {
        figures.push(
            {
                key: 'spend',
                label: t('today.ledger.spend'),
                value: a.spend === null ? '—' : money(a.spend),
                unit: a.spend === null ? undefined : currency,
                note: a.cost_per_order === null ? null : t('today.ledger.cost_per_order', { value: money(a.cost_per_order) }),
                href: a.links.spend,
            },
            {
                key: 'roas',
                label: t('today.ledger.roas'),
                value: a.real_roas === null ? '—' : `${formatRatio(a.real_roas, locale)}×`,
                note: a.meta_roas === null ? null : t('today.ledger.meta_says', { value: `${formatRatio(a.meta_roas, locale)}×` }),
                href: a.links.spend,
            },
        );
    }
    figures.push({
        key: 'chats',
        label: t('today.ledger.chats'),
        value: n(ch.new, locale),
        note: ch.new > 0 ? t('today.ledger.bot_closed', { share: formatShare(ch.bot_alone / ch.new, locale) }) : null,
        href: ch.links.new,
    });
    const q = ch.queue;
    const sla = q && q.sla_pct !== null ? q.sla_pct : null;
    figures.push({
        key: 'first_reply',
        label: t('today.ledger.first_reply'),
        value: formatAvgSeconds(ch.first_reply_avg_sec, locale),
        note:
            sla === null || !q
                ? null
                : t('today.ledger.sla', { pct: formatShare(sla / 100, locale), target: formatShare(q.sla_target_pct / 100, locale) }),
        tone: sla === null || !q ? 'neutral' : sla >= q.sla_target_pct ? 'up' : 'down',
        href: ch.links.first_reply,
    });

    return figures;
}

/** The hourly chart's bars: the hours from the first chat (or 9 ص) to the last hour of the window, oldest first. */
export function hourBars(byHour: number[] | undefined, lastHour: number, locale: Locale): { key: string; label: string; value: number }[] {
    if (!byHour || byHour.length !== 24) return [];
    const first = byHour.findIndex((v) => v > 0);
    const start = Math.min(first === -1 ? 9 : first, 9, lastHour);
    const fmt = new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG' : 'en-GB', { hour: 'numeric', hour12: true, timeZone: 'UTC' });

    return byHour.slice(start, lastHour + 1).map((value, i) => {
        const h = start + i;

        return { key: String(h), label: fmt.format(new Date(Date.UTC(2026, 0, 1, h))), value };
    });
}
