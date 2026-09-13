<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_title' => $this->product?->title,
            'title' => $this->title,
            'sku' => $this->sku,
            'price' => (float) $this->price,
            'stock' => (int) $this->inventory_quantity,
            'image_url' => $this->image_url ?? $this->product?->image_url,
        ];
    }
}
