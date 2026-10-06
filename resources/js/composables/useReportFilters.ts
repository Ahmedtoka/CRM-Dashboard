import { useVisitLoading } from '@/composables/useVisitLoading';
import type { ReportRange } from '@/types/admin';
import type { PlatformValue } from '@/types/crm';
import { router } from '@inertiajs/vue3';

/**
 * Report pages keep range/platform in the query string (the server resolves Cairo days),
 * so changing a filter is an Inertia visit that preserves scroll.
 */
export function useReportFilters(extra: () => Record<string, string | number | null | undefined> = () => ({})) {
    const { loading, track } = useVisitLoading();

    function visit(range: ReportRange, platform: PlatformValue | null): void {
        const query: Record<string, string | number> = { from: range.from, to: range.to };
        if (platform) query.platform = platform;

        for (const [key, value] of Object.entries(extra())) {
            if (value !== null && value !== undefined && value !== '') query[key] = value;
        }

        router.get(window.location.pathname, query, track({ preserveScroll: true, preserveState: true, replace: true }));
    }

    /** True while a range/platform visit is on its way (pages show skeletons for their tiles). */
    return { visit, loading };
}
