<?php

namespace App\Shopify\Sync\Mappers;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProductMapper
{
    public function upsert(array $product): MapResult
    {
        $p = Payload::isGraphql($product) ? $this->fromGraphql($product) : $product;
        $shopifyId = Payload::id($p['id'] ?? null) ?? throw new InvalidArgumentException('Shopify product payload has no id.');

        return DB::transaction(function () use ($p, $shopifyId) {
            $model = Product::withTrashed()->where('shopify_id', $shopifyId)->lockForUpdate()->first();

            if ($model !== null && StaleGuard::isStale($model->shopify_updated_at, $p['updated_at'] ?? null)) {
                return MapResult::Skipped;
            }

            $created = $model === null;
            $model ??= new Product(['shopify_id' => $shopifyId]);

            $model->fill([
                'title' => Payload::string($p['title'] ?? null) ?? ($model->title ?? ''),
                'handle' => Payload::string($p['handle'] ?? null),
                'image_url' => $p['image']['src'] ?? $p['images'][0]['src'] ?? null,
                'status' => Payload::lower($p['status'] ?? null) ?? 'active',
                'vendor' => Payload::string($p['vendor'] ?? null),
                'product_type' => Payload::string($p['product_type'] ?? null),
                'tags' => Payload::tags($p['tags'] ?? null),
                'shopify_updated_at' => Payload::time($p['updated_at'] ?? null),
                'synced_at' => now(),
            ]);
            // Re-created or re-published after a products/delete: bring the row back.
            $model->{$model->getDeletedAtColumn()} = null;
            $model->save();

            if (array_key_exists('variants', $p)) {
                $this->syncVariants($model, Payload::list($p['variants']), $p['images'] ?? []);
            }

            return $created ? MapResult::Created : MapResult::Updated;
        });
    }

    public function delete(string $shopifyProductId): void
    {
        $id = Payload::id($shopifyProductId);

        if ($id !== null) {
            Product::where('shopify_id', $id)->first()?->delete();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @param  array<int, array<string, mixed>>  $images
     */
    private function syncVariants(Product $product, array $variants, array $images): void
    {
        $imageSrc = collect($images)->mapWithKeys(fn ($img) => [Payload::id($img['id'] ?? null) => $img['src'] ?? null]);
        $kept = [];

        foreach ($variants as $v) {
            $variantId = Payload::id($v['id'] ?? null);

            if ($variantId === null) {
                continue;
            }

            $attributes = [
                'product_id' => $product->id,
                'sku' => Payload::string($v['sku'] ?? null),
                'title' => Payload::string($v['title'] ?? null),
                'price' => Payload::money($v['price'] ?? null),
                'compare_at_price' => Payload::moneyOrNull($v['compare_at_price'] ?? null),
                'barcode' => Payload::string($v['barcode'] ?? null),
                'inventory_item_id' => Payload::id($v['inventory_item_id'] ?? null),
                'inventory_policy' => Payload::lower($v['inventory_policy'] ?? null) ?? 'deny',
                'requires_shipping' => (bool) ($v['requires_shipping'] ?? true),
                'image_url' => $v['image_src'] ?? $imageSrc->get(Payload::id($v['image_id'] ?? null)),
                'shopify_updated_at' => Payload::time($v['updated_at'] ?? null),
            ];

            if (array_key_exists('inventory_quantity', $v) && $v['inventory_quantity'] !== null) {
                $attributes['inventory_quantity'] = (int) $v['inventory_quantity'];
            }

            ProductVariant::updateOrCreate(['shopify_id' => $variantId], $attributes);
            $kept[] = $variantId;
        }

        ProductVariant::where('product_id', $product->id)
            ->where(fn ($q) => $q->whereNull('shopify_id')->orWhereNotIn('shopify_id', $kept))
            ->delete();
    }

    private function fromGraphql(array $node): array
    {
        $image = $node['featuredImage']['url'] ?? $node['featuredMedia']['preview']['image']['url'] ?? null;

        return [
            'id' => $node['id'],
            'title' => $node['title'] ?? null,
            'handle' => $node['handle'] ?? null,
            'status' => $node['status'] ?? null,
            'vendor' => $node['vendor'] ?? null,
            'product_type' => $node['productType'] ?? null,
            'tags' => $node['tags'] ?? [],
            'updated_at' => $node['updatedAt'] ?? null,
            'image' => $image !== null ? ['src' => $image] : null,
            'images' => [],
            ...(array_key_exists('variants', $node) ? ['variants' => array_map(fn (array $v) => [
                'id' => $v['id'] ?? null,
                'title' => $v['title'] ?? null,
                'sku' => $v['sku'] ?? null,
                'price' => $v['price'] ?? null,
                'compare_at_price' => $v['compareAtPrice'] ?? null,
                'barcode' => $v['barcode'] ?? null,
                'inventory_quantity' => $v['inventoryQuantity'] ?? null,
                'inventory_policy' => $v['inventoryPolicy'] ?? null,
                'inventory_item_id' => $v['inventoryItem']['id'] ?? null,
                'requires_shipping' => $v['inventoryItem']['requiresShipping'] ?? $v['requiresShipping'] ?? true,
                'image_src' => $v['image']['url'] ?? null,
                'updated_at' => $v['updatedAt'] ?? null,
            ], Payload::list($node['variants']))] : []),
        ];
    }
}
