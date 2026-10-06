import { syncInertiaUrl } from '@/composables/useUrlFilters';
import { onScopeDispose, ref } from 'vue';

const read = (): number | null => {
    if (typeof window === 'undefined') return null;
    const v = Number(new URLSearchParams(window.location.search).get('ad'));
    return Number.isInteger(v) && v > 0 ? v : null;
};

/** The open ad drawer lives in the URL (`?ad=123`): notifications and alerts deep-link to one ad (U 3.4). Open pushes, close replaces. */
export function useAdDrawer() {
    const adId = ref<number | null>(read());
    const write = (id: number | null, mode: 'push' | 'replace') => {
        const url = new URL(window.location.href);
        if (id === null) url.searchParams.delete('ad');
        else url.searchParams.set('ad', String(id));
        syncInertiaUrl(url, mode);
    };
    const open = (id: number) => {
        adId.value = id;
        write(id, 'push');
    };
    const close = () => {
        adId.value = null;
        write(null, 'replace');
    };
    const onPop = () => (adId.value = read());
    window.addEventListener('popstate', onPop);
    onScopeDispose(() => window.removeEventListener('popstate', onPop));

    return { adId, open, close };
}
