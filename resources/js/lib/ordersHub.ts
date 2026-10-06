import { cairoToday } from '@/lib/format';
import type { AdsFilters } from '@/types/ads';

/** The AdDrawer needs a period: the page's, else (a triage view without dates) this Cairo month. */
export function drawerRange(range: { from: string | null; to: string | null }): AdsFilters {
    const today = cairoToday();
    return { from: range.from || `${today.slice(0, 8)}01`, to: range.to || today, platform: null, buyer: null };
}
