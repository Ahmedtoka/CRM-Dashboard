import { useVisitLoading } from '@/composables/useVisitLoading';
import { describe, expect, it, vi } from 'vitest';

describe('useVisitLoading', () => {
    it('flips loading around a visit and keeps the caller hooks', () => {
        const onFinish = vi.fn();
        const { loading, track } = useVisitLoading();
        const options = track({ only: ['orders'], onFinish });
        expect(options.only).toEqual(['orders']);
        options.onStart?.({} as never);
        expect(loading.value).toBe(true);
        options.onFinish?.({} as never);
        expect(loading.value).toBe(false);
        expect(onFinish).toHaveBeenCalledOnce();
    });
});
