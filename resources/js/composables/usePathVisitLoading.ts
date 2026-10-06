import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

/**
 * True while an Inertia visit to `path` runs (a filter change made by AdsFilterBar, a tab, a page link), so the page
 * can dim its content instead of looking frozen. Visits elsewhere (leaving the page) are ignored.
 */
export function usePathVisitLoading(path: string) {
    const loading = ref(false);
    const offStart = router.on?.('start', (e) => {
        const url = e.detail.visit.url;
        if (new URL(url.toString(), window.location.origin).pathname === path) loading.value = true;
    });
    const offFinish = router.on?.('finish', () => (loading.value = false));
    onBeforeUnmount(() => {
        offStart?.();
        offFinish?.();
    });

    return loading;
}
