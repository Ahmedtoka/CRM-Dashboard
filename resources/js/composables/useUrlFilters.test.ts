import { useUrlFilters } from '@/composables/useUrlFilters';
import { router } from '@inertiajs/vue3';
import { describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, watch } from 'vue';

describe('useUrlFilters', () => {
    it('a set() that changes nothing keeps the state and starts no visit', async () => {
        vi.mocked(router.replace).mockClear();
        const scope = effectScope();
        const visits: unknown[] = [];
        const api = scope.run(() => {
            const filters = useUrlFilters({ q: '', sort: '' }, { replaceKeys: ['q'] });
            watch(filters.query, (q) => visits.push(q));
            return filters;
        })!;

        const before = api.filters.value;
        api.set({ q: '' });
        await nextTick();

        expect(api.filters.value).toBe(before);
        expect(visits).toHaveLength(0);
        expect(router.replace).not.toHaveBeenCalled();

        api.set({ q: 'أحمد' });
        await nextTick();
        expect(visits).toEqual([{ q: 'أحمد' }]);
        scope.stop();
    });
});
