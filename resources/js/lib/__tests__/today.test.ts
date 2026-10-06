import { translate } from '@/i18n';
import { adsRows, chatsRows, formatAge, formatRatio, formatShare, logoHref, oldestStamp, ordersRows, todayNavItem, urgentText, whyRows } from '@/lib/today';
import type { AdsCard, ChatsCard, OrdersCard, WhyCard } from '@/types/today';
import { describe, expect, it } from 'vitest';

const t = (k: string, p?: Record<string, string | number>) => translate('en', k, p);

const chats: ChatsCard = {
    new: 410,
    from_ads: 254,
    ads_share: 0.62,
    bot_alone: 240,
    to_agent: 120,
    first_reply_avg_sec: 240,
    queue: {
        issued: 130,
        closed: {},
        closed_manual: 110,
        closed_total: 120,
        abandoned: 3,
        sla_replied: 100,
        sla_pct: 88,
        sla_target_pct: 90,
        avg_wait_seconds: 300,
    },
    rating: { count: 32, avg: 4.4, low: 2 },
    links: {
        new: '/reports/team?from=2026-10-06&to=2026-10-06',
        ads: '/inbox?flags=ad',
        bot: '/reports/bot?x',
        first_reply: '/reports/team?x',
        rating: '/reports/team?x#ratings',
        queue: '/board',
    },
};

describe('lib/today', () => {
    it('formats ratios and shares', () => {
        expect(formatRatio(4.4, 'en')).toBe('4.4');
        expect(formatRatio(3, 'en')).toBe('3.0');
        expect(formatRatio(null, 'en')).toBe('—');
        expect(formatShare(0.62, 'en')).toBe('62%');
        expect(formatShare(null, 'en')).toBe('—');
    });

    it('words each urgent item with its detail', () => {
        expect(urgentText({ key: 'lounge', count: 7, tone: 'warn', href: '/inbox?queue=waiting', longest_wait_seconds: 840 }, t, 'en')).toEqual({
            label: '7 waiting in the lounge',
            detail: expect.stringContaining('Longest wait'),
        });
        expect(urgentText({ key: 'cases_overdue', count: 1, tone: 'danger', href: '/cases?overdue=1' }, t, 'en').detail).toBeNull();
    });

    it('links every chats number to its screen and marks a missed SLA', () => {
        const rows = chatsRows(chats, t, 'en');
        expect(rows.find((r) => r.key === 'new')).toMatchObject({ value: '410', href: chats.links.new });
        expect(rows.find((r) => r.key === 'from_ads')).toMatchObject({ value: '254 (62%)', href: '/inbox?flags=ad' });
        expect(rows.find((r) => r.key === 'sla')).toMatchObject({ tone: 'bad', href: '/board' });
        expect(rows.find((r) => r.key === 'rating')).toMatchObject({ value: '4.4 (32 answers)', href: chats.links.rating });
    });

    it('hides the queue rows when the queue is off and the rating row when nobody rated', () => {
        const keys = chatsRows({ ...chats, queue: null, rating: { count: 0, avg: null, low: 0 } }, t, 'en').map((r) => r.key);
        expect(keys).not.toContain('sla');
        expect(keys).not.toContain('rating');
    });

    it('builds the orders, ads and why rows', () => {
        const orders: OrdersCard = {
            count: 53,
            total: 47000,
            from_chat: 38,
            from_store: 15,
            cancelled: 4,
            failed: 1,
            outcome_date: '2026-10-05',
            delivered: 61,
            returned: 5,
            links: { count: '/o', from_chat: '/oc', from_store: '/os', cancelled: '/ox', failed: '/of', delivered: '/od', returned: '/or' },
        };
        expect(ordersRows(orders, t, 'en').find((r) => r.key === 'failed')).toMatchObject({ value: '1', tone: 'bad', href: '/of' });

        const ads: AdsCard = {
            from: '2026-10-05',
            to: '2026-10-06',
            currency: 'EGP',
            spend: 12000,
            real_orders: 55,
            real_roas: 3.1,
            meta_roas: 2.4,
            cost_per_order: 218.18,
            best: { id: 1, name: 'A', orders: 14 },
            loser: { id: 2, name: 'B', spend: 900 },
            links: { spend: '/n', orders: '/e', best: '/e?ad=1', loser: '/e?ad=2' },
        };
        const a = adsRows(ads, t, 'en');
        expect(a.find((r) => r.key === 'roas')?.value).toContain('3.1');
        expect(a.find((r) => r.key === 'best')).toMatchObject({ label: 'Best ad: A', href: '/e?ad=1' });

        const why: WhyCard = {
            total: 100,
            ordered: 40,
            reasons: [{ key: 'price', count: 34, share: 0.34 }],
            top_size_out: null,
            links: { reasons: '/n#why', top_size_out: null },
        };
        expect(whyRows(why, t, 'en')[0]).toMatchObject({ label: 'Price', value: '34%', href: '/n#why' });
    });

    it('gives the nav item to admins and supervisors only', () => {
        expect(todayNavItem('admin', 'Today')?.href).toBe('/today');
        expect(todayNavItem('supervisor', 'Today')).not.toBeNull();
        expect(todayNavItem('moderator', 'Today')).toBeNull();
        expect(todayNavItem('media_buyer', 'Today')).toBeNull();
    });

    it('shows the oldest of the cache stamps', () => {
        expect(oldestStamp(['2026-10-06T09:01:00+00:00', '2026-10-06T09:00:30+00:00'])).toBe('2026-10-06T09:00:30+00:00');
        expect(oldestStamp(['2026-10-06T09:01:00+00:00', undefined])).toBe('2026-10-06T09:01:00+00:00');
        expect(oldestStamp([null, undefined])).toBeNull();
    });
});

describe('final review C4/C5', () => {
    const ar = (k: string, p?: Record<string, string | number>) => translate('ar', k, p);

    it('C5: the ads sync age is relative words, not h:mm', () => {
        expect(urgentText({ key: 'ads_sync', count: 2, tone: 'warn', href: '/ads/sync', age_minutes: 180 }, ar, 'ar').detail).toBe('آخر مزامنة من ٣ ساعات');
        expect(urgentText({ key: 'ads_sync', count: 2, tone: 'warn', href: '/ads/sync', age_minutes: 45 }, ar, 'ar').detail).toBe('آخر مزامنة من ٤٥ دقيقة');
        expect(formatAge(120, ar, 'ar')).toBe('ساعتين');
        expect(formatAge(3 * 1440, ar, 'ar')).toBe('٣ أيام');
        expect(urgentText({ key: 'ads_sync', count: 1, tone: 'warn', href: '/ads/sync', age_minutes: 60 }, t, 'en').detail).toBe('Last sync 1 hour ago');
    });

    it('C4: the logo leads admins and supervisors to /today, everyone else to their home', () => {
        expect(logoHref('admin')).toBe('/today');
        expect(logoHref('supervisor')).toBe('/today');
        expect(logoHref('moderator')).toBe('/');
        expect(logoHref('media_buyer')).toBe('/');
        expect(logoHref(undefined)).toBe('/');
    });
});
