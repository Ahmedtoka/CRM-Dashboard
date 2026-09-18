<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\ProductVariantResource;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

trait ProductSearch
{
    /**
     * Active-product variants from the local catalog cache; `q` (web) or `search` (API).
     * Matches SKU, title, product title or barcode (order drawer, spec §5.1).
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'search' => ['nullable', 'string', 'max:100']]);

        $term = trim((string) ($request->input('q') ?? $request->input('search') ?? ''));

        $variants = ProductVariant::query()
            ->with('product')
            ->whereHas('product', fn (Builder $p) => $p->where('status', 'active'))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhereHas('product', fn (Builder $p) => $p->where('title', 'like', "%{$term}%"))))
            ->orderBy('product_id')
            ->orderBy('id')
            ->limit(20)
            ->get();

        return ProductVariantResource::collection($variants);
    }
}
