import { useDensity } from '@/composables/useDensity';
import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('useDensity', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        localStorage.clear();
    });

    it('defaults to comfortable and persists changes per table', () => {
        const density = useDensity('orders');
        expect(density.value).toBe('comfortable');
        density.value = 'compact';
        expect(localStorage.getItem('crm.density.orders')).toBe('compact');
        expect(useDensity('orders').value).toBe('compact');
        expect(useDensity('customers').value).toBe('comfortable');
    });

    it('ignores junk values', () => {
        localStorage.setItem('crm.density.orders', 'huge');
        expect(useDensity('orders').value).toBe('comfortable');
    });

    it('survives blocked storage', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        const density = useDensity('orders', 'compact');
        expect(density.value).toBe('compact');
        expect(() => (density.value = 'comfortable')).not.toThrow();
    });
});
