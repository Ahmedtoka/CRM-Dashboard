import { syncInertiaUrl } from '@/composables/useUrlFilters';
import { onScopeDispose, ref } from 'vue';

const read = (): number | null => {
    if (typeof window === 'undefined') return null;
    const v = Number(new URLSearchParams(window.location.search).get('ad'));
    return Number.isInteger(v) && v > 0 ? v : null;
};

/**
 * The open ad drawer lives in the URL (`?ad=123`): notifications and alerts deep-link to one ad (U 3.4).
 * Opening pushes one history entry; closing a drawer this page opened goes Back over that entry (no dead Back step),
 * while a drawer that came in on a cold deep link closes by replacing the address. Switching ads replaces.
 */
export function useAdDrawer() {
    const adId = ref<number | null>(read());
    /** True while the current `?ad=` entry is one this page pushed. */
    let pushed = false;
    const write = (id: number | null, mode: 'push' | 'replace') => {
        const url = new URL(window.location.href);
        if (id === null) url.searchParams.delete('ad');
        else url.searchParams.set('ad', String(id));
        syncInertiaUrl(url, mode);
    };
    const open = (id: number) => {
        const mode = adId.value === null ? 'push' : 'replace';
        if (mode === 'push') pushed = true;
        adId.value = id;
        write(id, mode);
    };
    const close = () => {
        if (adId.value === null) return;
        adId.value = null;
        if (pushed) {
            pushed = false;
            window.history.back(); // popstate re-reads the address (no `ad`) and keeps adId null
        } else {
            write(null, 'replace');
        }
    };
    const onPop = () => {
        adId.value = read();
        // Back/forward moved off (or onto) an entry; only a fresh open() owns the next one.
        if (adId.value === null) pushed = false;
    };
    window.addEventListener('popstate', onPop);
    onScopeDispose(() => window.removeEventListener('popstate', onPop));

    return { adId, open, close };
}
