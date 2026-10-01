<?php

namespace App\Shopify\Commands;

use App\Models\Order;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RefreshShopifyOrders;
use Illuminate\Console\Command;

/**
 * Scheduled every 10 minutes (ShopifyServiceProvider, R8): queues the open
 * Shopify orders not read for --older-than minutes, oldest sync first, at most
 * --limit per run, in jobs of JOB_SIZE orders.
 */
class RefreshOpenOrdersCommand extends Command
{
    /** Order ids per queued job (each job queries Shopify in OrderRefresher::BATCH batches). */
    public const JOB_SIZE = 25;

    protected $signature = 'shopify:refresh-orders {--limit=250} {--older-than=10}';

    protected $description = 'Queue a Shopify refresh of open orders not synced recently';

    public function handle(IntegrationRepository $integrations): int
    {
        $integration = $integrations->current();

        // Same rule as the nightly reconcile (ReconcileShopify): only a connected,
        // real store; the demo/seeder domain never refreshes on a schedule.
        if ($integration?->status !== 'connected' || $integration->shop_domain === ShopifyIntegration::DEMO_SHOP_DOMAIN) {
            $this->info('skipped: not connected');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $older = max(0, (int) $this->option('older-than'));

        $ids = Order::openForSync()
            ->whereNotNull('shopify_order_id')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes($older)))
            ->orderByRaw('last_synced_at IS NOT NULL, last_synced_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        foreach ($ids->chunk(self::JOB_SIZE) as $chunk) {
            RefreshShopifyOrders::dispatch($chunk->values()->all());
        }

        $this->info('queued='.$ids->count());

        return self::SUCCESS;
    }
}
