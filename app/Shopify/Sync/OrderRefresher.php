<?php

namespace App\Shopify\Sync;

use App\Events\OrderUpdated;
use App\Models\Order;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Sync\Mappers\MapResult;
use App\Support\SafeBroadcast;
use Throwable;

/**
 * Reads given orders back from Shopify by id (spec §3.2, R8): the order page's
 * «تحديث من شوبيفاي», the on-view refresh of stale rows and the scheduled
 * refresh of open orders. Batches of SyncQueries::ORDERS_BY_IDS_BATCH through
 * `nodes(ids:)` with the paged sync's selection, each node completed and mapped
 * exactly as IncrementalSync does (row lock, stale guard, last_synced_at), and
 * the whole call logged as one `refresh` ShopifySyncRun.
 */
final class OrderRefresher
{
    public const BATCH = SyncQueries::ORDERS_BY_IDS_BATCH;

    public function __construct(
        private readonly ShopifyClient $client,
        private readonly ResourceRowMapper $rows,
        private readonly SyncRunRecorder $recorder,
        private readonly NestedCompleter $nested,
    ) {}

    /** The same refresher on another client (the web request's short-timeout one). */
    public function usingClient(ShopifyClient $client): self
    {
        return new self($client, $this->rows, $this->recorder, $this->nested->usingClient($client));
    }

    /**
     * `refreshed` counts orders read from Shopify (changed or not); `skipped` the
     * ones not asked for (not on Shopify / unknown id) or that Shopify no longer
     * has (null node, left untouched); `failed` the ones whose batch or mapping
     * failed. A failed batch never stops the next one.
     *
     * When $throw is true (the per-order endpoint), the first Shopify failure is
     * rethrown after the run is closed, instead of being counted.
     *
     * @param  array<int, int>  $orderIds
     * @return array{refreshed: int, skipped: int, failed: int}
     */
    public function refresh(array $orderIds, bool $throw = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        $gids = Order::query()
            ->whereKey($ids)
            ->whereNotNull('shopify_order_id')
            ->orderBy('id')
            ->pluck('shopify_order_id', 'id')
            ->map(fn ($shopifyId) => 'gid://shopify/Order/'.$shopifyId);

        $counts = ['refreshed' => 0, 'skipped' => count($ids) - $gids->count(), 'failed' => 0];

        if ($gids->isEmpty()) {
            return $counts;
        }

        $run = $this->recorder->open('refresh', 'orders');
        $summary = SyncRunSummary::empty();
        $error = null;

        foreach ($gids->chunk(self::BATCH) as $chunk) {
            try {
                $data = $this->client->query(SyncQueries::ordersByIds(), ['ids' => $chunk->values()->all()]);
            } catch (Throwable $e) {
                $counts['failed'] += $chunk->count();
                $summary = array_reduce(range(1, $chunk->count()), fn (SyncRunSummary $s) => $s->withFailure(), $summary);
                $this->recorder->recordError($run, 'batch '.$chunk->keys()->first().'…'.$chunk->keys()->last(), $e->getMessage());
                $error ??= $e;

                if ($throw) {
                    break;
                }

                continue;
            }

            $nodes = array_values(is_array($data['nodes'] ?? null) ? $data['nodes'] : []);

            foreach ($chunk->keys()->values() as $position => $orderId) {
                $node = $nodes[$position] ?? null;

                if (! is_array($node) || ! is_string($node['id'] ?? null)) {
                    $counts['skipped']++;

                    continue;
                }

                try {
                    $result = $this->rows->map('orders', $this->nested->complete('orders', $node));
                    $summary = $summary->withResult($result);
                    $counts['refreshed']++;

                    // Created/Updated (and an applied fulfillment/refund) broadcast from
                    // the mapper; an order where nothing was applied still has a new
                    // sync time the open screens should show: broadcast it once here.
                    if ($result === MapResult::Skipped && ! $this->rows->lastOrderChildrenApplied()
                        && ($order = Order::find($orderId)) !== null) {
                        SafeBroadcast::send(new OrderUpdated($order));
                    }
                } catch (Throwable $e) {
                    $counts['failed']++;
                    $summary = $summary->withFailure();
                    $this->recorder->recordError($run, (string) $node['id'], $e->getMessage());
                    $error ??= $e;
                }
            }

            $this->recorder->progress($run, $summary);
        }

        $this->recorder->close($run, $summary, $throw && $error !== null ? 'failed' : 'completed');

        if ($throw && $error !== null) {
            throw $error;
        }

        return $counts;
    }
}
