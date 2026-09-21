<?php

namespace App\Bot;

use App\Bot\Grounding\Synonyms;
use App\Bot\Grounding\VariantOptions;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Finds catalog lines (product/variant/price/stock) by keyword search on the
 * customer's message, for use as AI reply grounding (spec §5.7 step 4).
 *
 * Line shape: "{product title} | SKU {sku} | [{variant title}] | {price} جنيه | متاح {qty}"
 */
class CatalogSearch
{
    public function linesFor(string $text, int $limit = 8): array
    {
        $words = collect(preg_split('/\s+/u', trim($text)) ?: [])
            // Multibyte-safe: trim()'s byte charlist ate the 0xD8 lead byte of "ا" etc.
            ->map(fn ($w) => preg_replace('/^[؟?!.,،]+|[؟?!.,،]+$/u', '', $w) ?? $w)
            ->filter(fn ($w) => mb_strlen($w) >= 2)
            ->unique()
            ->values();

        if ($words->isEmpty()) {
            return [];
        }

        $variants = ProductVariant::query()
            ->with('product')
            ->where(function ($query) use ($words) {
                foreach ($words as $word) {
                    $query->orWhere('sku', 'like', "%{$word}%")
                        ->orWhereHas('product', fn ($p) => $p->where('title', 'like', "%{$word}%"));
                }
            })
            ->limit($limit)
            ->get()
            ->filter(fn (ProductVariant $v) => $v->product !== null);

        return $variants->map(fn (ProductVariant $v) => $this->formatLine($v))->all();
    }

    /**
     * One grounding line per product (spec §4.3), for the message bot:
     * "{title} | {price or min - max} جنيه | أحمر: S (3)، M (نفد) | أسود: L (2)".
     * Bounded to $limit active products; colour/size words and stop words are
     * dropped from the search so "الفستان الستان الاحمر متاح؟" finds "فستان ستان".
     *
     * @return list<string>
     */
    public function groupedLinesFor(string $text, int $limit = 5): array
    {
        return $this->productsFor($text, $limit)->map(fn (Product $p) => $this->productLine($p))->filter()->values()->all();
    }

    /**
     * The active products a message is about (the search behind groupedLinesFor and the product cards).
     * Every meaningful word must hit the title, the type, a tag or a SKU; when that finds nothing, any word may.
     *
     * @return Collection<int, Product>
     */
    public function productsFor(string $text, int $limit = 5): Collection
    {
        $normalizer = app(ArabicNormalizer::class);
        $stop = ['متاح', 'موجود', 'بكام', 'سعر', 'عندكم', 'لو', 'سمحتي', 'ممكن', 'ده', 'دي', 'فيه', 'مقاس', 'لون', 'عايزه', 'عايزة', 'عاوزه', 'عاوزة', 'محتاجه', 'محتاجة', 'صور', 'صوره', 'موديلات', 'موديل'];
        // Multibyte-safe punctuation trim: trim()'s byte charlist would eat the
        // lead byte (0xD8) of Arabic letters such as "ا" along with "؟"/"،".
        $tokens = collect(preg_split('/\s+/u', $normalizer->normalize($text)) ?: [])
            ->map(fn ($w) => preg_replace('/^[؟?!.,،]+|[؟?!.,،]+$/u', '', $w) ?? $w)
            ->map(fn ($w) => preg_replace('/^ال/u', '', $w) ?? $w)
            ->filter(fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, $stop, true) && Synonyms::color($w) === null && Synonyms::size($w) === null)
            ->unique()
            ->take(8) // keeps the OR/LIKE query bounded for long messages
            ->values();

        if ($tokens->isEmpty()) {
            return collect();
        }

        $hit = fn ($q, string $t) => $q->where('title', 'like', "%{$t}%")
            ->orWhere('product_type', 'like', "%{$t}%")
            ->orWhere('tags', 'like', "%{$t}%")
            ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$t}%"));

        $base = fn () => Product::query()
            ->with(['variants' => fn ($v) => $v->orderBy('id')])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'));

        $all = $base()->where(function ($q) use ($tokens, $hit) {
            foreach ($tokens as $t) {
                $q->where(fn ($w) => $hit($w, $t));
            }
        })->limit($limit)->get();

        if ($all->isNotEmpty() || $tokens->count() === 1) {
            return $all;
        }

        return $base()->where(function ($q) use ($tokens, $hit) {
            foreach ($tokens as $t) {
                $q->orWhere(fn ($w) => $hit($w, $t));
            }
        })->limit($limit)->get();
    }

    public function productLine(Product $p): ?string
    {
        if ($p->variants->isEmpty()) {
            return null;
        }

        $prices = $p->variants->map(fn ($v) => (float) $v->price);
        $price = $prices->min() === $prices->max()
            ? $this->formatPrice($prices->min())
            : $this->formatPrice($prices->min()).' - '.$this->formatPrice($prices->max());

        $byColor = [];
        foreach ($p->variants as $v) {
            ['color' => $color, 'size' => $size] = VariantOptions::parse($v->title);
            $isDefault = $v->title === null || in_array($v->title, ['', 'Default', 'Default Title'], true);
            $label = $size ?? ($color === null && ! $isDefault ? $v->title : 'مقاس واحد');
            $stock = (int) $v->inventory_quantity > 0 ? '('.(int) $v->inventory_quantity.')' : '(نفد)';
            $byColor[$color ?? 'لون واحد'][] = "{$label} {$stock}";
        }

        $parts = [$p->title, $price.' جنيه'];
        foreach ($byColor as $color => $sizes) {
            $parts[] = $color.': '.implode('، ', $sizes);
        }

        return implode(' | ', $parts);
    }

    private function formatLine(ProductVariant $variant): string
    {
        $parts = [
            $variant->product->title,
            'SKU '.($variant->sku ?: '-'),
        ];

        if ($variant->title !== null && ! in_array($variant->title, ['', 'Default'], true)) {
            $parts[] = $variant->title;
        }

        $parts[] = $this->formatPrice($variant->price).' جنيه';
        $parts[] = 'متاح '.(int) $variant->inventory_quantity;

        return implode(' | ', $parts);
    }

    private function formatPrice(mixed $price): string
    {
        $formatted = number_format((float) $price, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
