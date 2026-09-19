<?php

namespace App\Shopify\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the data imported from a Shopify store (products, orders, Shopify
 * customers, shipping zones, sync history) so a different store can be
 * connected cleanly, e.g. after testing on one store before going live on
 * another. Conversations and their customers are never deleted: a customer who
 * has messaged the page only loses the Shopify fields.
 */
class PurgeStoreDataCommand extends Command
{
    protected $signature = 'shopify:purge-data {--dry-run : Only count what would be deleted} {--force : Skip the confirmation and allow it while a store is connected}';

    protected $description = 'Delete the data imported from the previous Shopify store (keeps conversations)';

    public function handle(): int
    {
        $integration = ShopifyIntegration::query()->first();

        if ($integration?->status === 'connected' && ! $this->option('force')) {
            $this->error('A store is still connected ('.$integration->shop_domain.'). Disconnect it first, or pass --force.');

            return self::FAILURE;
        }

        $orders = Order::query()->whereNotNull('shopify_order_id');
        $products = Product::query()->whereNotNull('shopify_id');
        $shopifyCustomers = Customer::query()->whereNotNull('shopify_customer_id');
        $keptCustomers = (clone $shopifyCustomers)->where(fn ($q) => $q
            ->whereHas('conversations')
            ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('customer_identities')->whereColumn('customer_identities.customer_id', 'customers.id'))
            ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('support_cases')->whereColumn('support_cases.customer_id', 'customers.id')));

        $counts = [
            'orders' => (clone $orders)->count(),
            'products' => (clone $products)->count(),
            'customers deleted' => (clone $shopifyCustomers)->count() - (clone $keptCustomers)->count(),
            'customers kept (have conversations), Shopify fields cleared' => (clone $keptCustomers)->count(),
            'shipping zones' => Schema::hasTable('shipping_zones') ? DB::table('shipping_zones')->count() : 0,
        ];

        $this->table(['What', 'Count'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete this store data permanently?')) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($orders, $products, $shopifyCustomers, $keptCustomers) {
            // Order items, fulfillments, refunds and shipments cascade with their order;
            // support cases keep their order number and lose only the link.
            (clone $orders)->chunkById(500, fn ($chunk) => Order::whereIn('id', $chunk->pluck('id'))->delete());
            (clone $products)->chunkById(500, fn ($chunk) => Product::whereIn('id', $chunk->pluck('id'))->delete());

            $keptIds = (clone $keptCustomers)->pluck('id');
            DB::table('customer_addresses')->whereIn('customer_id', $keptIds)->delete();
            Customer::whereIn('id', $keptIds)->update(array_filter([
                'shopify_customer_id' => null,
                'shopify_orders_count' => Schema::hasColumn('customers', 'shopify_orders_count') ? 0 : null,
                'shopify_total_spent' => Schema::hasColumn('customers', 'shopify_total_spent') ? 0 : null,
                'shopify_updated_at' => null,
            ], fn ($v, $k) => Schema::hasColumn('customers', $k), ARRAY_FILTER_USE_BOTH));

            (clone $shopifyCustomers)->whereNotIn('id', $keptIds)
                ->chunkById(500, fn ($chunk) => Customer::whereIn('id', $chunk->pluck('id'))->delete());

            foreach (['shipping_rates', 'shipping_zone_regions', 'shipping_zones', 'shopify_sync_runs', 'shopify_webhook_subscriptions'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            ShopifyIntegration::query()->update(['import_state' => null]);
        });

        $this->info('Store data deleted. Connect the new store from Settings → Shopify.');

        return self::SUCCESS;
    }
}
