import { nextSort, parseSort, sortRows } from '@/lib/sort';
import { describe, expect, it } from 'vitest';

describe('sort helpers', () => {
    it('parses -key as desc', () => {
        expect(parseSort('-spend')).toEqual({ key: 'spend', dir: 'desc' });
        expect(parseSort('name')).toEqual({ key: 'name', dir: 'asc' });
        expect(parseSort('')).toBeNull();
        expect(parseSort(null)).toBeNull();
    });

    it('cycles desc then asc', () => {
        expect(nextSort(null, 'spend')).toBe('-spend');
        expect(nextSort('-spend', 'spend')).toBe('spend');
        expect(nextSort('spend', 'spend')).toBe('-spend');
        expect(nextSort('-spend', 'name')).toBe('-name');
    });

    it('sorts rows stably with nulls last', () => {
        const rows = [
            { id: 1, n: 2, s: 'ب' },
            { id: 2, n: null, s: 'أ' },
            { id: 3, n: 10, s: 'ج' },
            { id: 4, n: 2, s: 'د' },
        ];
        expect(sortRows(rows, '-n').map((r) => r.id)).toEqual([3, 1, 4, 2]);
        expect(sortRows(rows, 'n').map((r) => r.id)).toEqual([1, 4, 3, 2]);
        expect(sortRows(rows, 's').map((r) => r.id)).toEqual([2, 1, 3, 4]);
        expect(sortRows(rows, null)).toBe(rows);
    });
});
