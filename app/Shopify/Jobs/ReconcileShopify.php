<?php

namespace App\Shopify\Jobs;

use App\Enums\UserRole;
use App\Events\UserNotified;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\IncrementalSync;
use App\Support\SafeBroadcast;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Nightly reconciliation (spec §4.3): for products, customers and orders, sync
 * everything updated since that resource's last completed nightly run (default
 * 24 h back, never more than 72 h), then tell admins when orders were fixed.
 */
class ReconcileShopify implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const RESOURCES = ['products', 'customers', 'orders'];

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct()
    {
        // Longer than the `redis` connection's retry_after (90 s): on Redis it
        // runs on `redislong` (retry_after 3700 s); any other default
        // connection (database/sync locally) is kept as is.
        $this->onQueue('commercelong');

        if (config('queue.default') === 'redis') {
            $this->onConnection('redislong');
        }
    }

    public function handle(IncrementalSync $sync, IntegrationRepository $integrations): void
    {
        $integration = $integrations->current();

        // The demo/seeder domain never leaves a fake process behind on purpose: skip
        // it outright rather than trust whatever crm.shopify.driver happens to be.
        if ($integration?->status !== 'connected' || $integration->shop_domain === ShopifyIntegration::DEMO_SHOP_DOMAIN) {
            return;
        }

        $now = now();
        $ordersFixed = 0;
        $failure = null;

        foreach (self::RESOURCES as $resource) {
            try {
                $summary = $sync->run($resource, $this->since($resource, $now), $now, 'nightly');
            } catch (ShopifyException $e) {
                $failure = $e;

                if (in_array($e->kind, ['auth', 'not_connected'], true)) {
                    break;
                }

                continue; // A transport failure on one resource must not skip the others.
            }

            if ($resource === 'orders') {
                $ordersFixed = $summary->created + $summary->updated;
            }
        }

        if ($ordersFixed > 0) {
            $this->notifyAdmins($ordersFixed);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * The previous run's window end (its `range_to`, i.e. when it started querying)
     * rather than its `finished_at`, so updates made while it ran are not skipped.
     */
    private function since(string $resource, CarbonInterface $now): CarbonInterface
    {
        $last = ShopifySyncRun::query()
            ->where('type', 'nightly')
            ->where('resource', $resource)
            ->where('status', 'completed')
            ->latest('finished_at')
            ->first();

        $since = $last?->range_to ?? $last?->finished_at ?? $now->copy()->subDay();
        $floor = $now->copy()->subHours(72);

        return $since->lessThan($floor) ? $floor : $since;
    }

    private function notifyAdmins(int $count): void
    {
        User::query()
            ->where('is_active', true)
            ->where('role', UserRole::Admin->value)
            ->get()
            ->each(fn (User $u) => SafeBroadcast::send(new UserNotified($u->id, 'shopify.reconciled', [
                'count' => $count,
                'resource' => 'orders',
            ])));
    }
}
