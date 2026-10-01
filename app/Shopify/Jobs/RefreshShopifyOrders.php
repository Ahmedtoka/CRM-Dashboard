<?php

namespace App\Shopify\Jobs;

use App\Shopify\Sync\OrderRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Background refresh of a few orders from Shopify (spec §3.2, R8): queued by the
 * scheduled `shopify:refresh-orders` and by the on-view stale refresh. The
 * screens update through the OrderUpdated broadcast.
 */
class RefreshShopifyOrders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 600;

    /**
     * @param  array<int, int>  $orderIds
     */
    public function __construct(public readonly array $orderIds)
    {
        // The long Shopify queue, like ReconcileShopify / RunManualSync: `commercelong`
        // (Cloudways only accepts alphanumeric queue names), on the `redislong`
        // connection (retry_after 3700 s) when Redis is the default.
        $this->onQueue('commercelong');

        if (config('queue.default') === 'redis') {
            $this->onConnection('redislong');
        }
    }

    public function handle(OrderRefresher $refresher): void
    {
        $refresher->refresh($this->orderIds);
    }
}
