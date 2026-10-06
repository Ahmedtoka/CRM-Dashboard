import type { ProductVariant } from '@/types/crm';

/** What the bot noted before the handover (HandoverDigest): the drawer's starting point (C 5 #4). */
export interface OrderSuggestion {
    products: string[];
    sizes: string[];
    colors: string[];
}

const tokens = (text: string | null | undefined): string[] =>
    (text ?? '')
        .toLowerCase()
        .split(/[\s/,،|-]+/u)
        .map((x) => x.trim())
        .filter(Boolean);

const sellable = (v: ProductVariant) => v.inventory_policy === 'continue' || (v.stock ?? 0) > 0;

/** The variant the bot's size and colour point at (whole-word match), among the ones that can be sold. */
export function pickVariant(variants: ProductVariant[], sizes: string[], colors: string[]): ProductVariant | null {
    const pool = variants.filter(sellable);
    if (!pool.length) return null;
    const wantSizes = sizes.flatMap(tokens);
    const wantColors = colors.flatMap(tokens);
    const score = (v: ProductVariant) => {
        const words = tokens(v.title);

        return (wantSizes.some((s) => words.includes(s)) ? 2 : 0) + (wantColors.some((c) => words.includes(c)) ? 1 : 0);
    };

    return [...pool].sort((a, b) => score(b) - score(a) || a.id - b.id)[0];
}

/** One catalog search per product the bot noted (at most 3), in order; duplicates collapse; a failed search counts as not found. */
export async function prefillLines(
    search: (q: string) => Promise<ProductVariant[]>,
    s: OrderSuggestion,
): Promise<{ variants: ProductVariant[]; missing: string[] }> {
    const variants: ProductVariant[] = [];
    const missing: string[] = [];
    for (const name of s.products.slice(0, 3)) {
        const found = pickVariant(await search(name).catch(() => []), s.sizes, s.colors);
        if (found === null) missing.push(name);
        else if (!variants.some((x) => x.id === found.id)) variants.push(found);
    }

    return { variants, missing };
}
