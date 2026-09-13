<?php

namespace App\Bot;

use App\Models\ProductVariant;

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
            ->map(fn ($w) => trim($w, '؟?!.,،'))
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
