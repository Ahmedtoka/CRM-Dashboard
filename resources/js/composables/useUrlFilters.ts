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
 * Page filters that live in the URL: shareable links, and back/forward restore them.
 * Default values never appear in the URL; params listed in `keep` are left alone.
 */
export function useUrlFilters<T extends Record<string, FilterValue>>(defaults: T, opts: { history?: 'replace' | 'push'; keep?: string[] } = {}) {
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

    const write = () => {
        const url = new URL(window.location.href);
        for (const key of keys) {
            const v = serialise(filters.value[key]);
            if (v === null || same(filters.value[key], defaults[key])) url.searchParams.delete(key);
            else url.searchParams.set(key, v);
        }
        // Inertia keeps its page object in history.state: pass it through untouched.
        const state = window.history.state;
        if (opts.history === 'push') window.history.pushState(state, '', url);
        else window.history.replaceState(state, '', url);
    };

    const set = (patch: Partial<T>) => {
        filters.value = { ...filters.value, ...patch };
        write();
    };
    const clear = (except: (keyof T)[] = []) => {
        const next = { ...defaults };
        for (const k of except) next[k] = filters.value[k];
        filters.value = next;
        write();
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
