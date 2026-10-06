import type { Column } from '@/components/crm/DataTable.vue';

export const AD_PARTS = ['creative', 'spend', 'result', 'return', 'trend', 'status'] as const;
export type AdPart = (typeof AD_PARTS)[number];

/** Server sort key behind each sortable column (RunningCreatives::SORTS); the kit's DataTable sorts by column key. */
export const AD_SORT_KEYS: Partial<Record<AdPart, string>> = { spend: 'spend', result: 'conversations' };

/** Explorer columns. Compact density drops the sparkline (U 3.1); the phone card leaves it out too. */
export function adColumns(t: (k: string) => string, density: 'comfortable' | 'compact'): Column[] {
    const cols: Column[] = [
        { key: 'creative', label: t('ads.control.col.creative'), primary: true },
        { key: 'spend', label: t('ads.control.col.spend'), numeric: true, sortable: true },
        { key: 'result', label: t('ads.control.col.result'), numeric: true, sortable: true },
        { key: 'return', label: t('ads.control.col.return'), numeric: true },
        { key: 'trend', label: t('ads.control.col.trend'), hideOnMobile: true },
        { key: 'status', label: t('ads.control.col.status'), align: 'end' },
    ];

    return density === 'compact' ? cols.filter((c) => c.key !== 'trend') : cols;
}

/** `-conversations` (server) ⇄ `-result` (column key). Unknown server keys show no column arrow. */
export function columnSort(serverSort: string): string {
    const desc = serverSort.startsWith('-');
    const key = serverSort.replace(/^-/, '');
    const part = (Object.entries(AD_SORT_KEYS) as [AdPart, string][]).find(([, s]) => s === key)?.[0];

    return part ? `${desc ? '-' : ''}${part}` : '';
}

export function serverSort(columnSortValue: string): string {
    const desc = columnSortValue.startsWith('-');
    const part = columnSortValue.replace(/^-/, '') as AdPart;

    return `${desc ? '-' : ''}${AD_SORT_KEYS[part] ?? 'spend'}`;
}
