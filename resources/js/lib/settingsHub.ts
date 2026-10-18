/** One settings item as App\Settings\SettingsHub sends it: its link plus whatever its state line needs. */
export interface HubItem {
    id: string;
    href: string;
    count?: number;
    mine?: number;
    shared?: number;
    done?: number;
    total?: number;
    state?: 'on' | 'off' | 'connected' | 'error';
    text?: string | null;
    tone?: 'ok' | 'warn' | 'danger' | 'off';
    /** Saved replies of one person only («ردودي الجاهزة»). */
    personal?: boolean;
}

export interface HubGroup {
    id: string;
    items: HubItem[];
}

type Translate = (key: string, params?: Record<string, string | number>) => string;

/** The item's label key: a moderator's saved replies are «ردودي الجاهزة». */
export function hubItemLabel(item: HubItem): string {
    return item.personal ? 'settings_hub.items.my_quick_replies' : `settings_hub.items.${item.id}`;
}

/** The item's one-line state («٣ وسم», «متصل», «خلصت ٣ من ٥») and the dot beside it; null when it has none. */
export function hubItemState(item: HubItem, t: Translate): { text: string; tone?: HubItem['tone'] } | null {
    const n = item.count ?? 0;
    const s = (key: string, params?: Record<string, string | number>) => t(`settings_hub.state.${key}`, params);

    switch (item.id) {
        case 'profile':
            return item.text ? { text: item.text } : null;
        case 'quick_replies':
            return item.personal
                ? { text: s('my_replies', { mine: item.mine ?? 0 }) }
                : { text: s('replies', { shared: item.shared ?? 0, mine: item.mine ?? 0 }) };
        case 'users':
            return { text: s('users', { n }) };
        case 'queue':
            return { text: s(item.state === 'on' ? 'queue_on' : 'queue_off'), tone: item.state === 'on' ? 'ok' : 'off' };
        case 'tags':
            return { text: s('tags', { n }) };
        case 'integrations':
            return { text: item.tone === 'danger' ? s('channels_error') : s('channels', { n }), tone: item.tone };
        case 'shopify':
            return {
                text:
                    item.state === 'connected' ? (item.text ?? s('shopify_connected')) : s(item.state === 'error' ? 'shopify_error' : 'shopify_off'),
                tone: item.tone,
            };
        case 'cities':
            return { text: s('cities', { n }) };
        case 'branch_hours':
            return { text: s('branches', { n }) };
        case 'ad_accounts':
            return { text: s('ad_accounts', { n }) };
        case 'ad_buyers':
            return { text: s('ad_buyers', { n }) };
        case 'onboarding':
            return { text: s('onboarding', { done: item.done ?? 0, total: item.total ?? 0 }), tone: item.tone };
        case 'simulator':
        case 'latency':
            return { text: s('local_only'), tone: 'off' };
        default:
            return null;
    }
}

/** One link of the settings side list: the hub's items without their state (no query per page). */
export interface HubNavItem {
    id: string;
    href: string;
    personal?: boolean;
    /** Other paths that belong to this item (its tabs: /settings/password under «حسابي»). */
    match?: string[];
}

export interface HubNavGroup {
    id: string;
    items: HubNavItem[];
}

type HubRole = 'admin' | 'supervisor' | 'moderator' | 'media_buyer' | 'content';

/**
 * The settings side list (DESIGN §4 Hub + details) on every settings page. It mirrors App\Settings\SettingsHub's
 * visibility (and so the routes' middleware), so it never links to a 403; the states stay on the hub page.
 */
export function hubNav(role: HubRole | null | undefined, devTools = false): HubNavGroup[] {
    const admin = role === 'admin';
    const manager = admin || role === 'supervisor';
    const adsRole = role === 'media_buyer' || role === 'content';
    const groups: HubNavGroup[] = [
        {
            id: 'account',
            items: [
                { id: 'profile', href: '/settings/profile', match: ['/settings/password', '/settings/appearance', '/settings/notifications'] },
                ...(adsRole ? [] : [{ id: 'quick_replies', href: '/settings/quick-replies', personal: !manager }]),
            ],
        },
    ];

    if (manager) {
        groups.push({
            id: 'team',
            items: [
                ...(admin ? [{ id: 'users', href: '/settings/users' }] : []),
                { id: 'queue', href: '/settings/queue' },
                { id: 'tags', href: '/settings/tags' },
            ],
        });
    }

    if (admin) {
        groups.push(
            {
                id: 'channels',
                items: [
                    { id: 'integrations', href: '/settings/integrations', match: ['/settings/channels'] },
                    { id: 'shopify', href: '/settings/shopify' },
                ],
            },
            {
                id: 'store',
                items: [
                    { id: 'cities', href: '/settings/cities' },
                    { id: 'branch_hours', href: '/settings/branch-hours' },
                    { id: 'order_confirmations', href: '/settings/order-confirmations' },
                ],
            },
        );
    }

    if (manager) {
        groups.push({
            id: 'ads',
            items: [
                { id: 'ad_accounts', href: '/settings/ads/accounts', match: ['/settings/ads/sync'] },
                { id: 'ad_buyers', href: '/settings/ads/buyers' },
                { id: 'ad_rules', href: '/settings/ads/rules' },
            ],
        });
    }

    if (admin) {
        groups.push({ id: 'start', items: [{ id: 'onboarding', href: '/onboarding' }] });
        if (devTools) {
            groups.push({
                id: 'dev',
                items: [
                    { id: 'simulator', href: '/simulator' },
                    { id: 'latency', href: '/reports/latency' },
                ],
            });
        }
    }

    return groups;
}

/** The group and item a URL belongs to (exact path or a child/`match` path); null on the hub itself or elsewhere. */
export function hubLocate(groups: HubNavGroup[], url: string): { group: HubNavGroup; item: HubNavItem } | null {
    const path = url.split('?')[0].split('#')[0];
    const within = (p: string) => path === p || path.startsWith(`${p}/`);

    for (const group of groups) {
        for (const item of group.items) {
            if (within(item.href) || (item.match ?? []).some(within)) return { group, item };
        }
    }

    return null;
}

/** «الإعدادات › المجموعة › الصفحة» for AppLayout's top bar and the page header (the group has no page of its own). */
export function settingsCrumbs(t: Translate, groupId: string | null, title: string): { title: string; href?: string }[] {
    // «حسابي» and «ابدأ من هنا» are both the group and its page: say it once.
    const group = groupId ? t(`settings_hub.groups.${groupId}.title`) : null;

    return [
        { title: t('settings_hub.title'), href: '/settings' },
        ...(group && group !== title ? [{ title: group, href: `/settings#settings-${groupId}` }] : []),
        { title },
    ];
}
