import { router } from '@inertiajs/vue3';
import { computed, onScopeDispose, ref, type ComputedRef, type Ref } from 'vue';

export type FilterValue = string | number | boolean | null | string[];

function parse(raw: string | null, def: FilterValue): FilterValue {
    if (raw === null || raw === '') return def;
    if (Array.isArray(def)) return raw.split(',').filter(Boolean);
    if (typeof def === 'number') return Number.isFinite(Number(raw)) ? Number(raw) : def;
    if (typeof def === 'boolean') return raw === '1' || raw === 'true';
    // A default of null keeps strings (ids arrive as strings and callers coerce).
    return raw;
}

function serialise(v: FilterValue): string | null {
    if (v === null || v === '' || (Array.isArray(v) && v.length === 0)) return null;
    if (Array.isArray(v)) return v.join(',');
    if (typeof v === 'boolean') return v ? '1' : null;

    return String(v);
}

const same = (a: FilterValue, b: FilterValue) => serialise(a) === serialise(b);

/**
 * Changes the address through Inertia (a client-side visit: no request, state and scroll kept), not a bare
 * history call: Inertia re-writes the address from its own page.url whenever it saves a scroll position
 * (any scroll on the page), which silently undid a bare replaceState/pushState.
 */
let lastRequested: string | null = null;

export function syncInertiaUrl(url: URL, mode: 'push' | 'replace' = 'replace'): void {
    const target = url.pathname + url.search + url.hash;
    // Inertia writes history asynchronously, so window.location can lag a visit asked for this tick:
    // only skip when the address is already right AND no other address is still on its way.
    const current = window.location.pathname + window.location.search + window.location.hash;
    if (target === current && (lastRequested === null || lastRequested === current)) return;
    lastRequested = target;
    const visit = { url: target, preserveState: true, preserveScroll: true };
    if (mode === 'push') router.push(visit);
    else router.replace(visit);
}

/**
 * Page filters that live in the URL: shareable links, and back/forward restore them.
 * Default values never appear in the URL; params listed in `keep` are left alone.
 */
export function useUrlFilters<T extends Record<string, FilterValue>>(
    defaults: T,
    opts: { history?: 'replace' | 'push'; keep?: string[]; replaceKeys?: string[] } = {},
) {
    const keys = (Object.keys(defaults) as (keyof T & string)[]).filter((k) => !opts.keep?.includes(k));

    const read = (): T => {
        const p = new URLSearchParams(window.location.search);
        const out = { ...defaults };
        for (const key of keys) {
            (out[key] as FilterValue) = parse(p.get(key), defaults[key]);
        }

        return out;
    };

    const filters = ref(read()) as Ref<T>;

    /**
     * Every managed key is written from `filters` (the pending state), so two set() calls in the same
     * tick still produce the right address even though the first visit's history write has not landed
     * yet (window.location is only used for params this composable does not own, like `c`). Each call
     * is its own Inertia client visit; the later one wins.
     */
    const write = (mode: 'push' | 'replace') => {
        const url = new URL(window.location.href);
        for (const key of keys) {
            const v = serialise(filters.value[key]);
            if (v === null || same(filters.value[key], defaults[key])) url.searchParams.delete(key);
            else url.searchParams.set(key, v);
        }
        syncInertiaUrl(url, mode);
    };
    const historyMode = (): 'push' | 'replace' => (opts.history === 'push' ? 'push' : 'replace');

    const set = (patch: Partial<T>) => {
        const changed = keys.filter((k) => k in patch && !same(patch[k] as FilterValue, filters.value[k]));
        // Nothing new (e.g. the FilterBar re-emitting a search term after a reset): keep the same object, so
        // `query` watchers do not fire a second, identical visit.
        if (changed.length === 0) return;
        filters.value = { ...filters.value, ...patch };
        // Keys like a search term change on every debounced keystroke: they replace the entry, never push.
        const onlyReplaceKeys = changed.length > 0 && changed.every((k) => opts.replaceKeys?.includes(k));
        write(onlyReplaceKeys ? 'replace' : historyMode());
    };
    const clear = (except: (keyof T)[] = []) => {
        const next = { ...defaults };
        for (const k of except) next[k] = filters.value[k];
        filters.value = next;
        write(historyMode());
    };
    const activeKeys: ComputedRef<(keyof T)[]> = computed(() => keys.filter((k) => !same(filters.value[k], defaults[k])));
    const query: ComputedRef<Record<string, string | string[]>> = computed(() => {
        const q: Record<string, string> = {};
        for (const k of keys) {
            const v = serialise(filters.value[k]);
            if (v !== null && !same(filters.value[k], defaults[k])) q[k] = v;
        }

        return q;
    });

    const onPop = () => (filters.value = read());
    window.addEventListener('popstate', onPop);
    onScopeDispose(() => window.removeEventListener('popstate', onPop));

    return { filters, set, clear, activeKeys, query };
}
