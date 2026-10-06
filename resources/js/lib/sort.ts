export interface SortState {
    key: string;
    dir: 'asc' | 'desc';
}

/** `-spend` = spend descending, `spend` = ascending; empty = no explicit sort. */
export function parseSort(sort: string | null | undefined): SortState | null {
    if (!sort) return null;

    return sort.startsWith('-') ? { key: sort.slice(1), dir: 'desc' } : { key: sort, dir: 'asc' };
}

/** A header click: a new column starts descending, then flips. */
export function nextSort(current: string | null | undefined, key: string): string {
    const state = parseSort(current);

    return state?.key === key && state.dir === 'desc' ? key : `-${key}`;
}

/** Client-side sort for full (unpaginated) lists; server-paginated lists sort on the server. */
export function sortRows<T>(rows: T[], sort: string | null | undefined, accessor: (row: T, key: string) => unknown = (row, key) => (row as Record<string, unknown>)[key]): T[] {
    const state = parseSort(sort);
    if (!state) return rows;
    const sign = state.dir === 'desc' ? -1 : 1;

    return rows
        .map((row, index) => ({ row, index, value: accessor(row, state.key) }))
        .sort((a, b) => {
            const aNull = a.value === null || a.value === undefined || a.value === '';
            const bNull = b.value === null || b.value === undefined || b.value === '';
            if (aNull || bNull) return aNull === bNull ? a.index - b.index : aNull ? 1 : -1;
            const cmp =
                typeof a.value === 'number' && typeof b.value === 'number'
                    ? a.value - b.value
                    : String(a.value).localeCompare(String(b.value), 'ar', { numeric: true });

            return cmp === 0 ? a.index - b.index : cmp * sign;
        })
        .map((entry) => entry.row);
}
