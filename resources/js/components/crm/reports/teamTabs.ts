import { sectionTabs, type SectionTab } from '@/lib/sectionTabs';

type Translate = (key: string, params?: Record<string, string | number>) => string;

/** Tabs that read the platform filter too (the queue report is per business day only). */
const WITH_PLATFORM = new Set(['/reports/team', '/reports/quick-replies']);
/** Tabs whose own query keys travel with them too (the outcomes report keeps its grouping and moderator). */
const EXTRA_KEYS: Record<string, string[]> = { '/reports/outcomes': ['group', 'user'] };

/**
 * «الفريق» › الأداء · الطابور · الردود الجاهزة, with the page's period (and platform) carried onto every tab, so moving
 * between tabs keeps the range. Only the keys the current URL already names are carried: a tab's href never holds a
 * key the page lacks, so the active-tab match keeps working on the default range.
 */
export function teamTabs(t: Translate, url: string): SectionTab[] {
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    const carried: [string, string][] = [];
    for (const key of ['from', 'to', 'platform']) {
        const value = query.get(key);
        if (value) carried.push([key, value]);
    }

    return sectionTabs.team(t).map((tab) => {
        const keep = new URLSearchParams(carried.filter(([key]) => key !== 'platform' || WITH_PLATFORM.has(tab.href)));
        for (const key of EXTRA_KEYS[tab.href] ?? []) {
            const value = query.get(key);
            if (value) keep.set(key, value);
        }
        const qs = keep.toString();

        return qs ? { ...tab, href: `${tab.href}?${qs}` } : tab;
    });
}
