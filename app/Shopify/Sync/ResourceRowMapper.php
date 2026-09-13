<?php

namespace App\Shopify\Sync;

use App\Shopify\Sync\Mappers\CustomerMapper;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use App\Shopify\Sync\Mappers\Payload;
use App\Shopify\Sync\Mappers\ProductMapper;
use InvalidArgumentException;

/**
 * Maps one GraphQL node (bulk JSONL parent or paged query node) of a resource
 * through its mapper. Shared by BulkImporter and IncrementalSync.
 *
 * @internal
 */
final class ResourceRowMapper
{
    public function __construct(
        private readonly ProductMapper $products,
        private readonly CustomerMapper $customers,
        private readonly OrderMapper $orders,
    ) {}

    public function orders(): OrderMapper
    {
        return $this->orders;
    }

    /**
     * Every export selects `updatedAt`; a row without it cannot be stale-guarded
     * and is rejected as a row error.
     */
    public function map(string $resource, array $row): MapResult
    {
        if (! is_string($row['id'] ?? null) || $row['id'] === '') {
            throw new InvalidArgumentException('Row has no id.');
        }

        if (! is_string($row['updatedAt'] ?? null) || trim($row['updatedAt']) === '') {
            throw new InvalidArgumentException('Row has no updatedAt.');
        }

        return match ($resource) {
            'products' => $this->products->upsert($row),
            'customers' => $this->customers->upsert($row),
            'orders' => $this->order($row),
            default => throw new InvalidArgumentException("Unknown Shopify sync resource [{$resource}]."),
        };
    }

    /**
     * Fulfillments and refunds ride on the order node; each has its own stale /
     * idempotency guard, so they are applied even when the order itself is unchanged.
     */
    private function order(array $row): MapResult
    {
        $result = $this->orders->upsert($row);

        foreach (Payload::list($row['fulfillments'] ?? []) as $fulfillment) {
            if (is_string($fulfillment['id'] ?? null)) {
                $this->orders->applyFulfillment($fulfillment + ['orderId' => $row['id']]);
            }
        }

        foreach (Payload::list($row['refunds'] ?? []) as $refund) {
            if (is_string($refund['id'] ?? null)) {
                $this->orders->applyRefund($refund + ['orderId' => $row['id']]);
            }
        }

        return $result;
    }
}
