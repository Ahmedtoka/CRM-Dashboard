import { pickVariant, prefillLines } from '@/lib/orderPrefill';
import type { ProductVariant } from '@/types/crm';
import { describe, expect, it } from 'vitest';

const v = (id: number, title: string, stock = 5, policy: 'deny' | 'continue' = 'deny'): ProductVariant => ({
    id,
    product_id: 1,
    product_title: 'اسدال كتان',
    title,
    sku: null,
    price: 850,
    stock,
    inventory_policy: policy,
    image_url: null,
});

describe('orderPrefill', () => {
    it('prefers the variant matching the size, then the colour, by whole word', () => {
        const variants = [v(1, 'S / اسود'), v(2, 'L / اسود'), v(3, 'L / بيج'), v(4, 'XL / بيج')];
        expect(pickVariant(variants, ['L'], ['بيج'])?.id).toBe(3);
        expect(pickVariant(variants, ['L'], [])?.id).toBe(2);
        expect(pickVariant(variants, [], [])?.id).toBe(1);
    });

    it('skips variants out of stock that cannot be oversold', () => {
        expect(pickVariant([v(1, 'L / بيج', 0), v(2, 'M / بيج')], ['L'], ['بيج'])?.id).toBe(2);
        expect(pickVariant([v(1, 'L', 0, 'continue')], ['L'], [])?.id).toBe(1);
        expect(pickVariant([v(1, 'L', 0)], ['L'], [])).toBeNull();
    });

    it('searches each product once (max 3) and reports what it could not find', async () => {
        const calls: string[] = [];
        const search = async (q: string) => {
            calls.push(q);
            return q === 'اسدال كتان' ? [v(3, 'L / بيج')] : [];
        };
        const r = await prefillLines(search, { products: ['اسدال كتان', 'عباية', 'طرحة', 'شنطة'], sizes: ['L'], colors: ['بيج'] });
        expect(calls).toEqual(['اسدال كتان', 'عباية', 'طرحة']);
        expect(r.variants.map((x) => x.id)).toEqual([3]);
        expect(r.missing).toEqual(['عباية', 'طرحة']);
    });

    it('treats a failed search as not found', async () => {
        const r = await prefillLines(async () => Promise.reject(new Error('x')), { products: ['اسدال'], sizes: [], colors: [] });
        expect(r).toEqual({ variants: [], missing: ['اسدال'] });
    });
});
