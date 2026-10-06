/**
 * URL state of every Ads page (U 4.4): shared params travel between pages, list params belong to one list, presets are
 * plain links (U 4.3). Filter change = replace history; preset = push (the caller decides).
 * Sort strings use the kit's `-key` = descending convention (lib/sort), re-exported here for the Ads pages.
 */
export { nextSort, parseSort } from '@/lib/sort';

export type Query = Record<string, string>;
export type PresetKey = 'mine' | 'no_result' | 'winners' | 'tired' | 'out_of_stock' | 'changed_today';

export const BASE_KEYS = ['range', 'from', 'to', 'platform', 'buyer', 'accounts'] as const;
export const LIST_KEYS = ['status', 'objective', 'health', 'changed', 'q', 'sort', 'view', 'page', 'per_page'] as const;
export const EXPLORER_DEFAULTS: Query = { status: 'running', sort: '-spend', view: 'table' };

export const PRESETS: { key: PresetKey; query: Query }[] = [
    { key: 'mine', query: { buyer: 'me' } },
    { key: 'no_result', query: { status: 'running', health: 'no_result', sort: '-spend' } },
    { key: 'winners', query: { view: 'cards', health: 'winning', sort: '-roas' } },
    { key: 'tired', query: { health: 'tired', sort: '-spend' } },
    { key: 'out_of_stock', query: { health: 'out_of_stock' } },
    { key: 'changed_today', query: { changed: 'today', status: 'all' } },
];

export function readQuery(search: string): Query {
    const p = new URLSearchParams(search);
    const out: Query = {};
    const accounts: string[] = [];
    p.forEach((value, key) => {
        if (key === 'accounts[]' || key === 'accounts') accounts.push(...value.split(',').filter(Boolean));
        else if (value !== '') out[key] = value;
    });
    if (accounts.length) out.accounts = accounts.join(',');

    return out;
}

export function buildHref(path: string, q: Query): string {
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(q)) if (v !== '' && v !== undefined && v !== null) p.set(k, v);
    const s = p.toString();

    return s ? `${path}?${s}` : path;
}

/** Sets (or clears with null) one param; defaults never appear in the URL; any filter change goes back to page 1. */
export function withParam(current: Query, key: string, value: string | null, defaults: Query = {}): Query {
    const next: Query = { ...current };
    delete next.page;
    if (value === null || value === '' || defaults[key] === value) delete next[key];
    else next[key] = value;
    if (key === 'page' && value !== null && value !== '1') next.page = value;

    return next;
}

export function presetQuery(current: Query, key: PresetKey): Query {
    const preset = PRESETS.find((p) => p.key === key);
    const next: Query = {};
    for (const [k, v] of Object.entries(current)) {
        if (!(LIST_KEYS as readonly string[]).includes(k)) next[k] = v;
    }
    if (key === 'mine') delete next.buyer;

    return { ...next, ...(preset?.query ?? {}) };
}

export function activePreset(current: Query, defaults: Query = EXPLORER_DEFAULTS): { key: PresetKey; modified: boolean } | null {
    const value = (k: string) => current[k] ?? defaults[k] ?? '';
    for (const preset of PRESETS) {
        const matches = Object.entries(preset.query).every(([k, v]) => value(k) === v);
        if (!matches) continue;
        // The preset's own keys match; any other non-default list filter means the user edited it.
        const modified = (LIST_KEYS as readonly string[])
            .filter((k) => k !== 'page' && k !== 'per_page' && !(k in preset.query))
            .some((k) => current[k] !== undefined && current[k] !== (defaults[k] ?? ''));
        // A bare default list (no preset key set explicitly) is not a preset: require one preset key in the URL.
        if (Object.keys(preset.query).some((k) => current[k] !== undefined)) return { key: preset.key, modified };
    }

    return null;
}

export function carryQuery(current: Query): Query {
    const out: Query = {};
    for (const k of BASE_KEYS) if (current[k] !== undefined) out[k] = current[k];

    return out;
}
