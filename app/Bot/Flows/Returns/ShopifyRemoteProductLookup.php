<?php

namespace App\Bot\Flows\Returns;

use App\Models\Product;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Sync\Mappers\Payload;
use App\Shopify\Sync\Mappers\ProductMapper;

/**
 * Looks a product up by handle in the Shopify Admin API (only while the store is
 * connected) and saves it to the catalog with the same mapper as the sync, so
 * the next link to it is answered locally.
 */
final class ShopifyRemoteProductLookup implements RemoteProductLookup
{
    public const QUERY = <<<'GRAPHQL'
        query productByHandle($handle: String!) {
          productByIdentifier(identifier: { handle: $handle }) {
            id title handle status vendor productType tags updatedAt
            featuredImage { url }
            variants(first: 100) {
              nodes {
                id title sku price compareAtPrice barcode inventoryQuantity inventoryPolicy updatedAt
                inventoryItem { id requiresShipping }
                image { url }
              }
            }
          }
        }
    GRAPHQL;

    public function __construct(
        private readonly ShopifyClient $client,
        private readonly IntegrationRepository $integrations,
        private readonly ProductMapper $mapper,
    ) {}

    public function byHandle(string $handle): ?Product
    {
        if ($this->integrations->current()?->status !== 'connected') {
            return null;
        }

        $node = $this->client->query(self::QUERY, ['handle' => $handle])['productByIdentifier'] ?? null;

        if (! is_array($node) || ! Payload::isGraphql($node)) {
            return null;
        }

        $this->mapper->upsert($node);

        return Product::query()->where('shopify_id', Payload::id($node['id']))->first();
    }
}
