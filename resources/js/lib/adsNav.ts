import { buildHref, carryQuery, readQuery } from '@/lib/adsFilters';
import type { NavItem } from '@/types';
import type { Role } from '@/types/crm';

export interface AdsNavCounts {
    /** Cached open-decisions count (shared prop `adsDecisions`); null = not cached yet, no badge. */
    decisions: number | null;
    library: number;
}

/** D8: النهارده · محتاج قرار · الإعلانات · الأرقام · المكتبة · الإعداد (setup for supervisor+). Report links carry the shared filters (U 4.4). */
export function adsNavChildren(role: Role, t: (key: string) => string, counts: AdsNavCounts, search: string): NavItem[] {
    const q = buildHref('', carryQuery(readQuery(search)));
    const query = q === '' ? undefined : q;
    const items: NavItem[] = [
        { title: t('nav.ads_today'), href: '/ads', exact: true, query },
        { title: t('nav.ads_decisions'), href: '/ads/decisions', badge: counts.decisions, query, match: ['/ads/approvals'] },
        { title: t('nav.ads_explorer'), href: '/ads/explorer', query },
        { title: t('nav.ads_numbers'), href: '/ads/numbers', query, match: ['/ads/buyers'] },
        { title: t('nav.ads_library'), href: '/ads/materials', badge: counts.library, match: ['/ads/collections', '/ads/stock'] },
    ];
    if (role === 'admin' || role === 'supervisor') {
        items.push({ title: t('nav.ads_setup'), href: '/ads/setup', match: ['/ads/accounts', '/ads/sync', '/ads/setup'] });
    }

    return items;
}
