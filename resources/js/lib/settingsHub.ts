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
}

export interface HubGroup {
    id: string;
    items: HubItem[];
}

type Translate = (key: string, params?: Record<string, string | number>) => string;

/** The item's one-line state («٣ وسم», «متصل», «خلصت ٣ من ٥») and the dot beside it; null when it has none. */
export function hubItemState(item: HubItem, t: Translate): { text: string; tone?: HubItem['tone'] } | null {
    const n = item.count ?? 0;
    const s = (key: string, params?: Record<string, string | number>) => t(`settings_hub.state.${key}`, params);

    switch (item.id) {
        case 'profile':
            return item.text ? { text: item.text } : null;
        case 'quick_replies':
            return item.shared === undefined ? null : { text: s('replies', { shared: item.shared, mine: item.mine ?? 0 }) };
        case 'users':
            return { text: s('users', { n }) };
        case 'queue':
            return { text: s(item.state === 'on' ? 'queue_on' : 'queue_off'), tone: item.state === 'on' ? 'ok' : 'off' };
        case 'tags':
            return { text: s('tags', { n }) };
        case 'integrations':
            return { text: item.tone === 'danger' ? s('channels_error') : s('channels', { n }), tone: item.tone };
        case 'shopify':
            return { text: item.state === 'connected' ? (item.text ?? s('shopify_connected')) : s(item.state === 'error' ? 'shopify_error' : 'shopify_off'), tone: item.tone };
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
