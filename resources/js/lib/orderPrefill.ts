import type { ProductVariant } from '@/types/crm';

/** What the bot noted before the handover (HandoverDigest): the drawer's starting point (C 5 #4). */
export interface OrderSuggestion {
    products: string[];
    sizes: string[];
    colors: string[];
}

/** Why a noted product did not become a line: not in the catalog, nothing in stock, or not in her size / colour. */
export type MissReason = 'product' | 'stock' | 'size' | 'color';
export interface PrefillMiss {
    product: string;
    reason: MissReason;
    /** The size or colour asked for (size / color misses). */
    value?: string;
}

export type VariantPick = { variant: ProductVariant } | { miss: MissReason; value?: string };

const tokens = (text: string | null | undefined): string[] =>
    (text ?? '')
        .toLowerCase()
        .split(/[\s/,،|-]+/u)
        .map((x) => x.trim())
        .filter(Boolean);

const sellable = (v: ProductVariant) => v.inventory_policy === 'continue' || (v.stock ?? 0) > 0;

/** The search hits of the one product whose title best matches the noted name (every word of a shorter name counts). */
function productHits(variants: ProductVariant[], name: string): ProductVariant[] {
    const want = tokens(name);
    if (!want.length) return [];
    const score = (v: ProductVariant) => {
        const words = tokens(v.product_title);

        return want.filter((w) => words.includes(w)).length;
    };
    let best = 0;
    let productId: number | null = null;
    for (const v of variants) {
        const s = score(v);
        if (s > best) {
            best = s;
            productId = v.product_id;
        }
    }
    // At least half the noted words (rounded up) must be in the title: «عباية» never becomes «طرحة».
    if (productId === null || best < Math.ceil(want.length / 2)) return [];

    return variants.filter((v) => v.product_id === productId);
}

/**
 * The variant the bot's size and colour point at, among the ones that can be sold, of the product
 * that matches the noted name. A noted size (or colour) that no sellable variant has is a miss,
 * never another variant; with no size and no colour noted the first sellable variant is taken.
 */
export function pickVariant(variants: ProductVariant[], product: string, sizes: string[], colors: string[]): VariantPick {
    const hits = productHits(variants, product);
    if (!hits.length) return { miss: 'product' };
    const pool = hits.filter(sellable);
    const wantSizes = sizes.map(tokens).filter((t) => t.length);
    const wantColors = colors.map(tokens).filter((t) => t.length);
    /** One noted value (any of them) must match in full: every word of «بيج فاتح», so never «بيج غامق». */
    const has = (v: ProductVariant, want: string[][]) => {
        if (!want.length) return true;
        const words = tokens(v.title);

        return want.some((value) => value.every((w) => words.includes(w)));
    };

    if (!pool.length) return { miss: 'stock' };
    const sized = pool.filter((v) => has(v, wantSizes));
    if (!sized.length) return { miss: 'size', value: sizes.join('، ') };
    const match = sized.filter((v) => has(v, wantColors));
    if (!match.length) return { miss: 'color', value: colors.join('، ') };

    return { variant: [...match].sort((a, b) => a.id - b.id)[0] };
}

/** One catalog search per product the bot noted (at most 3), in order; duplicates collapse; a failed search counts as not found. */
export async function prefillLines(
    search: (q: string) => Promise<ProductVariant[]>,
    s: OrderSuggestion,
): Promise<{ variants: ProductVariant[]; missing: PrefillMiss[] }> {
    const variants: ProductVariant[] = [];
    const missing: PrefillMiss[] = [];
    for (const name of s.products.slice(0, 3)) {
        const pick = pickVariant(await search(name).catch(() => []), name, s.sizes, s.colors);
        if ('variant' in pick) {
            if (!variants.some((x) => x.id === pick.variant.id)) variants.push(pick.variant);
        } else {
            missing.push({ product: name, reason: pick.miss, ...(pick.value !== undefined ? { value: pick.value } : {}) });
        }
    }

    return { variants, missing };
}
