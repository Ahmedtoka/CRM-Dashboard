<?php

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Facades\DB;

it('deletes the previous store data but keeps customers who have conversations', function () {
    ShopifyIntegration::create(['shop_domain' => 'old.myshopify.com', 'status' => 'disconnected', 'import_state' => ['stages' => ['orders' => ['status' => 'completed']]]]);
    $shopOnly = Customer::factory()->create(['shopify_customer_id' => 'gid://shopify/Customer/1']);
    $chatting = Customer::factory()->create(['shopify_customer_id' => 'gid://shopify/Customer/2']);
    $conversation = Conversation::factory()->create(['customer_id' => $chatting->id]);
    $localOnly = Customer::factory()->create(['shopify_customer_id' => null]);
    Order::factory()->create(['customer_id' => $shopOnly->id, 'shopify_order_id' => 'gid://shopify/Order/9']);
    $crmOrder = Order::factory()->create(['customer_id' => $localOnly->id, 'shopify_order_id' => null]);
    Product::factory()->create(['shopify_id' => 'gid://shopify/Product/5']);
    $localProduct = Product::factory()->create(['shopify_id' => null]);

    $this->artisan('shopify:purge-data', ['--force' => true])->assertSuccessful();

    expect(Order::whereNotNull('shopify_order_id')->count())->toBe(0)
        ->and(Order::whereKey($crmOrder->id)->exists())->toBeTrue()
        ->and(Product::whereNotNull('shopify_id')->count())->toBe(0)
        ->and(Product::whereKey($localProduct->id)->exists())->toBeTrue()
        ->and(Customer::whereKey($shopOnly->id)->exists())->toBeFalse()
        ->and(Customer::whereKey($chatting->id)->exists())->toBeTrue()
        ->and($chatting->fresh()->shopify_customer_id)->toBeNull()
        ->and(Conversation::whereKey($conversation->id)->exists())->toBeTrue()
        ->and(Customer::whereKey($localOnly->id)->exists())->toBeTrue()
        ->and(ShopifyIntegration::first()->import_state)->toBeNull();
});

it('only counts on a dry run', function () {
    Product::factory()->create(['shopify_id' => 'gid://shopify/Product/5']);

    $this->artisan('shopify:purge-data', ['--dry-run' => true])->assertSuccessful();

    expect(Product::count())->toBe(1);
});

it('refuses while a store is connected unless forced', function () {
    ShopifyIntegration::create(['shop_domain' => 'live.myshopify.com', 'status' => 'connected']);
    Product::factory()->create(['shopify_id' => 'gid://shopify/Product/5']);

    $this->artisan('shopify:purge-data')->assertFailed();

    expect(Product::count())->toBe(1);
});

it('clears shipping zones', function () {
    DB::table('shipping_zones')->insert(['name' => 'Domestic', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('shopify:purge-data', ['--force' => true])->assertSuccessful();

    expect(DB::table('shipping_zones')->count())->toBe(0);
});
