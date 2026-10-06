import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { sync } = vi.hoisted(() => ({ sync: vi.fn() }));
vi.mock('@/composables/useUrlFilters', () => ({ syncInertiaUrl: sync }));

import { useAdDrawer } from '@/composables/useAdDrawer';
import { effectScope } from 'vue';

describe('useAdDrawer', () => {
    let scope: ReturnType<typeof effectScope>;
    beforeEach(() => {
        sync.mockReset();
        scope = effectScope();
    });
    afterEach(() => scope.stop());

    it('pushes on open and goes Back on close, leaving no dead history step', () => {
        window.history.replaceState({}, '', '/ads/explorer?range=last7');
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        const d = scope.run(() => useAdDrawer())!;
        d.open(7);
        expect(sync.mock.calls[0][1]).toBe('push');
        expect(String(sync.mock.calls[0][0])).toContain('ad=7');
        d.open(9);
        expect(sync.mock.calls[1][1]).toBe('replace');
        d.close();
        expect(back).toHaveBeenCalledTimes(1);
        expect(sync).toHaveBeenCalledTimes(2);
        expect(d.adId.value).toBeNull();
    });

    it('closes a cold deep link by replacing the address', () => {
        window.history.replaceState({}, '', '/ads?ad=5');
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        const d = scope.run(() => useAdDrawer())!;
        expect(d.adId.value).toBe(5);
        d.close();
        expect(back).not.toHaveBeenCalled();
        expect(sync.mock.calls[0][1]).toBe('replace');
        expect(String(sync.mock.calls[0][0])).not.toContain('ad=');
    });

    it('follows the browser Back button', () => {
        window.history.replaceState({}, '', '/ads?ad=5');
        const d = scope.run(() => useAdDrawer())!;
        window.history.replaceState({}, '', '/ads');
        window.dispatchEvent(new PopStateEvent('popstate'));
        expect(d.adId.value).toBeNull();
    });
});
