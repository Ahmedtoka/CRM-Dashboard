<?php

namespace App\Shopify\Commands;

use App\Models\Order;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RefreshShopifyOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled every 10 minutes (ShopifyServiceProvider, R8): queues the open
 * Shopify orders not read for --older-than minutes, oldest sync first, at most
 * --limit per run, in jobs of JOB_SIZE orders.
 *
 * The default limit is 60 (controller ruling, not the spec's 250): 60 orders
 * are ~9 refresh queries of up to ~938 requested points each per 10 minutes,
 * well inside Shopify's 50 points/s restore, which the bot's live order
 * lookups share. 250 would be ~36 such queries per run.
 */
class RefreshOpenOrdersCommand extends Command
{
    /** Order ids per queued job (each job queries Shopify in OrderRefresher::BATCH batches). */
    public const JOB_SIZE = 25;

    /**
     * An order queued by this command is not queued again for this long (the same
     * `orders.refresh.{id}` lock OrderController::refreshStale takes for 300 s): a busy
     * commercelong worker would otherwise get the same orders every 10 minutes.
     */
    public const QUEUED_LOCK_SECONDS = 900;

    /**
     * Only orders placed in the last this-many days are refreshed on the schedule. Couriers rarely
     * report `delivered` back to Shopify, so without a horizon nearly every fulfilled order stays
     * "open" and the job keeps cycling the whole history; older ones still refresh when opened
     * (OrderController::refreshStale) and in the nightly reconcile.
     */
    public const HORIZON_DAYS = 60;

    /** Candidates read per run, as a multiple of --limit, so orders still locked do not starve the rest. */
    private const CANDIDATE_FACTOR = 5;

    protected $signature = 'shopify:refresh-orders {--limit=60} {--older-than=10}';

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
            ->where(fn ($q) => $q->whereNull('placed_at')->orWhere('placed_at', '>=', now()->subDays(self::HORIZON_DAYS)))
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes($older)))
            ->orderByRaw('last_synced_at IS NOT NULL, last_synced_at')
            ->orderBy('id')
            ->limit($limit * self::CANDIDATE_FACTOR)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        // Skip the ones queued in the last 15 minutes (still waiting, or just refreshed by a job
        // that has not stamped last_synced_at yet); claim the lock only for the ids taken.
        $taken = collect();
        foreach ($ids as $id) {
            if ($taken->count() >= $limit) {
                break;
            }
            if (Cache::add("orders.refresh.{$id}", 1, self::QUEUED_LOCK_SECONDS)) {
                $taken->push($id);
            }
        }
        $ids = $taken;

        foreach ($ids->chunk(self::JOB_SIZE) as $chunk) {
            RefreshShopifyOrders::dispatch($chunk->values()->all());
        }

        $this->info('queued='.$ids->count());

        return self::SUCCESS;
    }
}
