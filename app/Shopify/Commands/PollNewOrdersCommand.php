<?php

namespace App\Shopify\Commands;

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Events\ShopifyOrderCreated;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\Mappers\Payload;
use App\Shopify\Sync\NestedCompleter;
use App\Shopify\Sync\ResourceRowMapper;
use App\Shopify\Sync\SyncQueries;
use App\Support\DataFloor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net for the WhatsApp order confirmation (D4, round 2026-10-17). Scheduled every 5 minutes:
 * reads the orders Shopify created since the last cursor (minus OVERLAP_MINUTES), oldest first.
 * An order the CRM does not have yet is imported through the normal order mapper and announced
 * with ShopifyOrderCreated, exactly as the orders/create webhook does, so an order whose webhook
 * was lost still gets its confirmation within ~5 minutes instead of never.
 *
 * An order that is already here but has no confirmation row (orders/create lost, orders/updated
 * imported it) is announced too, while confirmations are on. Duplicates are harmless:
 * OrderConfirmations keeps one row per order (unique order_id) and applies its own age, test,
 * POS and data-floor rules, so a late or old order is recorded as skipped, never sent.
 */
class PollNewOrdersCommand extends Command
{
    /** Cache key holding the ISO-8601 moment the next run reads from. */
    public const CURSOR_KEY = 'shopify.poll_new_orders.cursor';

    /** Each run re-reads this much before the cursor: orders committed late in Shopify are not missed. */
    public const OVERLAP_MINUTES = 10;

    /** Pages of SyncQueries::PAGE_SIZES['orders'] read per run; the rest waits for the next run. */
    public const MAX_PAGES = 10;

    protected $signature = 'crm:shopify:poll-new-orders';

    protected $description = 'Import Shopify orders whose orders/create webhook never arrived, and start their WhatsApp confirmation';

    public function handle(
        IntegrationRepository $integrations,
        ShopifyClient $client,
        ResourceRowMapper $rows,
        NestedCompleter $nested,
        ConfirmationSettings $settings,
    ): int {
        $integration = $integrations->current();

        // Same rule as the scheduled refresh: only a connected, real store.
        if ($integration?->status !== 'connected' || $integration->shop_domain === ShopifyIntegration::DEMO_SHOP_DOMAIN) {
            $this->info('skipped: not connected');

            return self::SUCCESS;
        }

        $startedAt = CarbonImmutable::now();
        $since = $this->since($settings);
        $filter = "created_at:>='".$since->utc()->format('Y-m-d\TH:i:s\Z')."'";
        $cursor = null;
        $pages = 0;
        $lastCreatedAt = null;
        $imported = 0;
        $announced = 0;

        try {
            do {
                $data = $client->query(SyncQueries::pollNewOrders(), ['cursor' => $cursor, 'query' => $filter]);
                $page = is_array($data['orders'] ?? null) ? $data['orders'] : [];
                $pages++;

                foreach (Payload::list($page) as $node) {
                    [$isNew, $didAnnounce] = $this->handleNode($node, $rows, $nested, $settings);
                    $imported += (int) $isNew;
                    $announced += (int) $didAnnounce;
                    $lastCreatedAt = is_string($node['createdAt'] ?? null) ? $node['createdAt'] : $lastCreatedAt;
                }

                $cursor = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
            } while ($cursor !== null && $pages < self::MAX_PAGES);
        } catch (Throwable $e) {
            // The cursor is left where it was: the next run reads the same window again.
            Log::warning('shopify.poll_new_orders.failed', ['error' => $e->getMessage()]);
            $this->error('failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // Stopped at the page cap: continue from the last order read; otherwise from this run's start.
        $next = $cursor !== null && $lastCreatedAt !== null ? CarbonImmutable::parse($lastCreatedAt) : $startedAt;
        Cache::forever(self::CURSOR_KEY, $next->toIso8601String());

        if ($imported > 0 || $announced > 0) {
            Log::info('shopify.poll_new_orders', ['imported' => $imported, 'announced' => $announced, 'since' => $since->toIso8601String()]);
        }

        $this->info("imported={$imported} announced={$announced}");

        return self::SUCCESS;
    }

    /**
     * The cursor minus the overlap; with no cursor yet, the confirmation max-age window (an older
     * order would only be skipped as too old). Never before the data floor.
     */
    private function since(ConfirmationSettings $settings): CarbonImmutable
    {
        $stored = Cache::get(self::CURSOR_KEY);

        try {
            $base = is_string($stored) ? CarbonImmutable::parse($stored)->subMinutes(self::OVERLAP_MINUTES) : null;
        } catch (Throwable) {
            $base = null;
        }

        $base ??= CarbonImmutable::now()->subMinutes($settings->maxOrderAgeMinutes());

        return DataFloor::clamp($base);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: bool, 1: bool} [imported now, announced]
     */
    private function handleNode(array $node, ResourceRowMapper $rows, NestedCompleter $nested, ConfirmationSettings $settings): array
    {
        $shopifyId = Payload::id($node['id'] ?? null);

        if ($shopifyId === null) {
            return [false, false];
        }

        // withLoadTest: a load-test row holding this id is "known"; it is never imported twice or announced.
        $existing = Order::withLoadTest()->where('shopify_order_id', $shopifyId)->first(['id', 'is_load_test']);

        if ($existing?->is_load_test) {
            return [false, false];
        }

        if ($existing !== null) {
            if (! $settings->enabled() || OrderConfirmation::where('order_id', $existing->id)->exists()) {
                return [false, false];
            }

            return [false, $this->announce((int) $existing->id, $node)];
        }

        try {
            $rows->map('orders', $nested->complete('orders', $node));
        } catch (Throwable $e) {
            Log::warning('shopify.poll_new_orders.map_failed', ['order' => $node['id'], 'error' => $e->getMessage()]);

            return [false, false];
        }

        $orderId = Order::where('shopify_order_id', $shopifyId)->value('id');

        // Not stored (e.g. created before the data floor): nothing to confirm.
        if ($orderId === null) {
            return [false, false];
        }

        return [true, $this->announce((int) $orderId, $node)];
    }

    /** The same event the orders/create webhook raises (ShopifyWebhookProcessor::routeOrderCreate). */
    private function announce(int $orderId, array $node): bool
    {
        return (bool) rescue(function () use ($orderId, $node) {
            ShopifyOrderCreated::dispatch(
                $orderId,
                (bool) ($node['test'] ?? false),
                Payload::string($node['sourceName'] ?? null),
            );

            return true;
        }, false, report: true);
    }
}
