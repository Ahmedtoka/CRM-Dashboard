<?php

namespace App\Shopify\Sync\Mappers;

use App\Ads\Materials\Jobs\CheckProductStock;
use App\Models\ProductVariant;

final class InventoryMapper
{
    /**
     * Single-location: the payload's `available` becomes the variant quantity.
     * Multi-location summing is deferred to the inventory ledger; mappers never call Shopify.
     */
    public function apply(array $inventoryLevel): void
    {
        $itemId = Payload::id(
            $inventoryLevel['inventory_item_id'] ?? $inventoryLevel['item']['id'] ?? $inventoryLevel['inventoryItem']['id'] ?? null
        );

        $available = $inventoryLevel['available'] ?? collect($inventoryLevel['quantities'] ?? [])
            ->firstWhere('name', 'available')['quantity'] ?? null;

        if ($itemId === null || ! is_numeric($available)) {
            return;
        }

        $variants = ProductVariant::where('inventory_item_id', $itemId);
        $productIds = (clone $variants)->pluck('product_id');

        $variants->update([
            'inventory_quantity' => (int) $available,
            'updated_at' => now(),
        ]);

        CheckProductStock::dispatchFor($productIds);
    }
}
