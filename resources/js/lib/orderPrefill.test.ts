import { pickVariant, prefillLines } from '@/lib/orderPrefill';
import type { ProductVariant } from '@/types/crm';
import { describe, expect, it } from 'vitest';

const v = (id: number, title: string, stock = 5, policy: 'deny' | 'continue' = 'deny', product = 'اسدال كتان', productId = 1): ProductVariant => ({
    id,
    product_id: productId,
    product_title: product,
    title,
    sku: null,
    price: 850,
    stock,
    inventory_policy: policy,
    image_url: null,
});

const id = (pick: ReturnType<typeof pickVariant>) => ('variant' in pick ? pick.variant.id : null);

describe('orderPrefill', () => {
    it('takes the variant matching the size and the colour, by whole word', () => {
        const variants = [v(1, 'S / اسود'), v(2, 'L / اسود'), v(3, 'L / بيج'), v(4, 'XL / بيج')];
        expect(id(pickVariant(variants, 'اسدال كتان', ['L'], ['بيج']))).toBe(3);
        expect(id(pickVariant(variants, 'اسدال كتان', ['L'], []))).toBe(2);
        expect(id(pickVariant(variants, 'اسدال كتان', [], []))).toBe(1);
    });

    it('never swaps her size or colour for another variant: it is a miss', () => {
        const variants = [v(1, 'L / بيج', 0), v(2, 'M / بيج'), v(3, 'L / اسود')];
        expect(pickVariant(variants, 'اسدال كتان', ['L'], ['بيج'])).toEqual({ miss: 'color', value: 'بيج' });
        expect(pickVariant([v(1, 'M / بيج')], 'اسدال كتان', ['L'], [])).toEqual({ miss: 'size', value: 'L' });
        expect(id(pickVariant([v(1, 'L', 0, 'continue')], 'اسدال كتان', ['L'], []))).toBe(1);
        expect(pickVariant([v(1, 'L', 0)], 'اسدال كتان', [], [])).toEqual({ miss: 'stock' });
    });

    it('needs every word of a noted colour or size: «بيج فاتح» is never «بيج غامق»', () => {
        const variants = [v(1, 'L / بيج غامق'), v(2, 'L / بيج فاتح'), v(3, 'XL / اسود')];
        expect(id(pickVariant(variants, 'اسدال كتان', ['L'], ['بيج فاتح']))).toBe(2);
        expect(pickVariant([v(1, 'L / بيج غامق')], 'اسدال كتان', ['L'], ['بيج فاتح'])).toEqual({ miss: 'color', value: 'بيج فاتح' });
        // Any one of several noted values may match, each one in full.
        expect(id(pickVariant(variants, 'اسدال كتان', [], ['كحلي', 'بيج فاتح']))).toBe(2);
    });

    it('keeps to the product whose title matches the noted name', () => {
        const hits = [v(7, 'L / بيج', 5, 'deny', 'طرحة شيفون', 2), v(8, 'L / بيج', 5, 'deny', 'عباية سادة', 3)];
        expect(id(pickVariant(hits, 'عباية', ['L'], ['بيج']))).toBe(8);
        expect(pickVariant([v(7, 'L', 5, 'deny', 'طرحة شيفون', 2)], 'عباية', ['L'], [])).toEqual({ miss: 'product' });
    });

    it('searches each product once (max 3) and reports what it could not use and why', async () => {
        const calls: string[] = [];
        const search = async (q: string) => {
            calls.push(q);
            if (q === 'اسدال كتان') return [v(3, 'L / بيج')];
            if (q === 'عباية') return [v(9, 'M / بيج', 5, 'deny', 'عباية سادة', 4)];
            return [];
        };
        const r = await prefillLines(search, { products: ['اسدال كتان', 'عباية', 'طرحة', 'شنطة'], sizes: ['L'], colors: ['بيج'] });
        expect(calls).toEqual(['اسدال كتان', 'عباية', 'طرحة']);
        expect(r.variants.map((x) => x.id)).toEqual([3]);
        expect(r.missing).toEqual([
            { product: 'عباية', reason: 'size', value: 'L' },
            { product: 'طرحة', reason: 'product' },
        ]);
    });

    it('treats a failed search as not found', async () => {
        const r = await prefillLines(async () => Promise.reject(new Error('x')), { products: ['اسدال'], sizes: [], colors: [] });
        expect(r).toEqual({ variants: [], missing: [{ product: 'اسدال', reason: 'product' }] });
    });
});
