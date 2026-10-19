import type { NavItem } from '@/types';
import type { Role } from '@/types/crm';
import {
    BarChart3,
    Bot,
    CircleAlert,
    Gauge,
    Inbox,
    LayoutDashboard,
    Library,
    Megaphone,
    MessagesSquare,
    Package,
    PhoneCall,
    Rocket,
    Settings,
    Undo2,
} from 'lucide-vue-next';

/** Waiting work per menu item (shared prop `navBadges` + the ads counters). Missing or 0 = no badge. */
export interface NavBadges {
    confirmations?: number;
    cases?: number;
    /** An open case is past its SLA. */
    cases_urgent?: boolean;
    board?: number;
    /** Someone waits in the room longer than the queue SLA. */
    board_urgent?: boolean;
    learning?: number;
    decisions?: number | null;
    /** Drafts waiting for this buyer's review. */
    launches?: number;
    /** Launches sent back to this content person. */
    launches_content?: number;
}

export interface NavContext {
    role: Role;
    canSeeBoard: boolean;
    devTools: boolean;
    /** Admin only, until every required step is done. */
    onboarding: { done: number; total: number } | null;
    badges: NavBadges;
    /** The shared ads filters (`?range=7d…`) carried on the ads report links (U 4.4). */
    adsQuery: string;
    t: (key: string, params?: Record<string, string | number>) => string;
}

const SEPARATOR: NavItem = { title: '', href: '#separator', separator: true };

const count = (n: number | null | undefined) => (n && n > 0 ? n : undefined);

/**
 * One menu per role (IA §3.4): grouped for admins and supervisors, flat for the moderator, the media buyer and
 * content. Developer pages live in the settings hub, never the sidebar.
 */
export function buildNav(ctx: NavContext): NavItem[] {
    const { t, badges } = ctx;
    const query = ctx.adsQuery === '' ? undefined : ctx.adsQuery;
    const settings: NavItem = { title: t('nav.settings'), href: '/settings', icon: Settings, match: ['/simulator', '/reports/latency'] };

    const decisions: NavItem = {
        title: t('nav.ads_decisions'),
        href: '/ads/decisions',
        badge: count(badges.decisions),
        query,
        match: ['/ads/approvals'],
    };
    const explorer: NavItem = { title: t('nav.ads_explorer'), href: '/ads/explorer', query, match: ['/ads/creatives'] };
    const numbers: NavItem = { title: t('nav.ads_numbers'), href: '/ads/numbers', query, match: ['/ads/buyers'] };
    const library: NavItem = { title: t('nav.ads_library'), href: '/ads/materials', match: ['/ads/collections', '/ads/stock'] };
    const launches: NavItem = { title: t('nav.launches'), href: '/ads/launches', badge: count(badges.launches) };

    if (ctx.role === 'content') {
        return [
            { ...library, icon: Library },
            { title: t('nav.ads_launches'), href: '/ads/launches', icon: Rocket, badge: count(badges.launches_content) },
            SEPARATOR,
            settings,
        ];
    }

    if (ctx.role === 'media_buyer') {
        return [
            { title: t('nav.today'), href: '/today/ads', icon: Gauge, query },
            { ...decisions, icon: CircleAlert },
            { ...launches, icon: Rocket },
            { ...explorer, icon: Megaphone },
            { ...numbers, icon: BarChart3 },
            { ...library, icon: Library },
            SEPARATOR,
            settings,
        ];
    }

    const room: NavItem = {
        title: t('nav.board'),
        href: '/board',
        badge: count(badges.board),
        badgeUrgent: badges.board_urgent === true && count(badges.board) !== undefined,
    };
    const cases: NavItem = {
        title: t('nav.after_sale'),
        href: '/cases',
        badge: count(badges.cases),
        badgeUrgent: badges.cases_urgent === true && count(badges.cases) !== undefined,
    };

    if (ctx.role === 'moderator') {
        return [
            { title: t('nav.inbox'), href: '/inbox', icon: Inbox },
            { title: t('nav.comments'), href: '/comments', icon: MessagesSquare },
            ...(ctx.canSeeBoard ? [{ ...room, icon: LayoutDashboard }] : []),
            { title: t('nav.confirmations'), href: '/orders/confirmations', icon: PhoneCall, badge: count(badges.confirmations) },
            { title: t('nav.orders'), href: '/orders', icon: Package, exclude: ['/orders/confirmations'] },
            { ...cases, icon: Undo2 },
            { title: t('nav.reports_me'), href: '/reports/me', icon: BarChart3 },
            SEPARATOR,
            settings,
        ];
    }

    // Admin and supervisor: seven sections in the order of the day (A1).
    const onboarding = ctx.role === 'admin' && ctx.onboarding && ctx.onboarding.done < ctx.onboarding.total ? ctx.onboarding : null;

    return [
        ...(onboarding ? [{ title: t('nav.onboarding'), href: '/onboarding', icon: Rocket, progress: { ...onboarding } }] : []),
        { title: t('nav.today'), href: '/today', icon: Gauge },
        {
            title: t('nav.chats'),
            href: '/inbox',
            icon: Inbox,
            children: [
                { title: t('nav.inbox'), href: '/inbox' },
                { title: t('nav.comments'), href: '/comments' },
                ...(ctx.canSeeBoard ? [room] : []),
            ],
        },
        {
            title: t('nav.orders_group'),
            href: '/orders',
            icon: Package,
            children: [
                { title: t('nav.orders'), href: '/orders', badge: count(badges.confirmations), match: ['/orders/confirmations'] },
                cases,
                { title: t('nav.customers'), href: '/customers' },
            ],
        },
        { title: t('nav.ads'), href: '/ads', icon: Megaphone, children: [decisions, launches, explorer, numbers, library] },
        {
            title: t('nav.reports'),
            href: '/reports',
            icon: BarChart3,
            children: [
                { title: t('nav.team'), href: '/reports/team', match: ['/reports/queue', '/reports/quick-replies', '/reports/users'] },
                { title: t('nav.reports_activity'), href: '/reports/activity' },
            ],
        },
        {
            title: t('nav.bot'),
            href: '/bot',
            icon: Bot,
            children: [
                { title: t('nav.bot_run'), href: '/bot', exact: true },
                { title: t('bot_brain.nav.brain'), href: '/bot/brain', match: ['/bot/costs', '/bot/guide'] },
                { title: t('nav.bot_flows'), href: '/bot/flows', match: ['/bot/intents'] },
                { title: t('nav.bot_copy'), href: '/bot/replies', match: ['/bot/knowledge', '/bot/translations'] },
                { title: t('nav.bot_testing'), href: '/bot/test-links', match: ['/bot/test-sessions'] },
                { title: t('nav.bot_learning'), href: '/bot/learning', match: ['/bot/feedback'], badge: count(badges.learning) },
                { title: t('nav.bot_performance'), href: '/bot/performance' },
            ],
        },
        settings,
    ];
}

/** A closed group shows the sum of its children's badges, red when any of them is late (DESIGN §3 AppSidebar). */
export function groupBadge(item: NavItem): { count: number; urgent: boolean } {
    const children = item.children ?? [];

    return {
        count: children.reduce((sum, c) => sum + (c.badge ?? 0), 0),
        urgent: children.some((c) => c.badgeUrgent === true),
    };
}

/** Path match only (carried query strings never matter); `match` lists other prefixes that light the item up. */
export function isNavActive(item: NavItem, url: string): boolean {
    if (item.separator) return false;
    const path = url.split('?')[0];
    const hit = (href: string) => path === href || path.startsWith(`${href}/`);
    if ((item.exclude ?? []).some(hit)) return false;

    return path === item.href || (!item.exact && path.startsWith(`${item.href}/`)) || (item.match ?? []).some(hit);
}

/** A group is open (and lit) when one of its children is the current page. */
export function isGroupActive(item: NavItem, url: string): boolean {
    return (item.children ?? []).some((c) => isNavActive(c, url));
}
