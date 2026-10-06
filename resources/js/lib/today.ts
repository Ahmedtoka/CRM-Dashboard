import { formatNumber, type Locale } from '@/i18n';
import { formatAvgSeconds, formatCount, formatMinutes, formatMoney, formatSeconds } from '@/lib/format';
import type { NavItem } from '@/types';
import type { Role } from '@/types/crm';
import type { AdsCard, ChatsCard, OrdersCard, UrgentItem, WhyCard } from '@/types/today';
import type { LucideIcon } from 'lucide-vue-next';

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

export function urgentText(item: UrgentItem, t: Translate, locale: Locale): { label: string; detail: string | null } {
    const label = t(`today.urgent.${item.key}`, { n: n(item.count, locale) });
    if (item.key === 'lounge' && item.longest_wait_seconds != null)
        return { label, detail: t('today.urgent.lounge_longest', { time: formatSeconds(item.longest_wait_seconds, locale) }) };
    if (item.key === 'ads_sync' && item.age_minutes != null)
        return { label, detail: t('today.urgent.ads_sync_age', { time: formatMinutes(item.age_minutes, locale) }) };

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
    const day = o.outcome_date;
    return [
        { key: 'count', label: t('today.orders.count'), value: `${n(o.count, locale)} · ${formatMoney(o.total, locale)}`, href: o.links.count },
        { key: 'from_chat', label: t('today.orders.from_chat'), value: n(o.from_chat, locale), href: o.links.from_chat },
        { key: 'from_store', label: t('today.orders.from_store'), value: n(o.from_store, locale), href: o.links.from_store },
        { key: 'cancelled', label: t('today.orders.cancelled'), value: n(o.cancelled, locale), href: o.links.cancelled },
        { key: 'failed', label: t('today.orders.failed'), value: n(o.failed, locale), href: o.links.failed, tone: o.failed > 0 ? 'bad' : 'default' },
        {
            key: 'delivered',
            label: t('today.orders.delivered', { day: formatTodayDate(day, locale) }),
            value: n(o.delivered, locale),
            href: o.links.delivered,
        },
        {
            key: 'returned',
            label: t('today.orders.returned', { day: formatTodayDate(day, locale) }),
            value: n(o.returned, locale),
            href: o.links.returned,
        },
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

/** «النهارده» first in the menu, for admins and supervisors only (spec §6). */
export function todayNavItem(role: Role | undefined, title: string, icon?: LucideIcon): NavItem | null {
    return role === 'admin' || role === 'supervisor' ? { title, href: '/today', icon, exact: true } : null;
}
