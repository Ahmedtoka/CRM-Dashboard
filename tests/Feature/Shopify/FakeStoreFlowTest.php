<?php

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Str;

it('connects the fake store, imports, and creates an order end to end', function () {
    config(['crm.shopify.driver' => 'fake', 'crm.drivers.commerce' => 'live']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->post('/settings/shopify/connect', ['shop_domain' => 'demo-store.myshopify.com', 'access_token' => 'fake', 'api_secret' => 'fake'])->assertRedirect();
    expect(ShopifyIntegration::first()->status)->toBe('connected')->and(Product::count())->toBeGreaterThan(0)->and(ShippingZone::count())->toBeGreaterThan(0);

    $conv = Conversation::factory()->create();
    $variant = Product::first()->variants()->where('inventory_quantity', '>', 0)->firstOrFail();
    $this->actingAs($admin)->postJson("/inbox/conversations/{$conv->id}/orders", [
        'idempotency_key' => (string) Str::uuid(), 'type' => 'cod',
        'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Test', 'phone' => '01001234567', 'province_code' => 'C', 'city' => 'Nasr City', 'address1' => 'x'],
    ])->assertSuccessful();
    $order = Order::latest('id')->first();
    expect($order->status->value)->toBe('confirmed')->and($order->shopify_order_id)->not->toBeNull()->and((float) $order->shipping_fee)->toBe(60.0);
});

it('adopts the fake store order a lost-response attempt created instead of creating a second one', function () {
    config(['crm.shopify.driver' => 'fake', 'crm.drivers.commerce' => 'live']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->post('/settings/shopify/connect', ['shop_domain' => 'demo-store.myshopify.com', 'access_token' => 'fake', 'api_secret' => 'fake'])->assertRedirect();

    $conv = Conversation::factory()->create();
    $variant = Product::first()->variants()->where('inventory_quantity', '>', 0)->firstOrFail();
    $this->actingAs($admin)->postJson("/inbox/conversations/{$conv->id}/orders", [
        'idempotency_key' => (string) Str::uuid(), 'type' => 'cod',
        'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Test', 'phone' => '01001234567', 'province_code' => 'C', 'city' => 'Nasr City', 'address1' => 'x'],
    ])->assertSuccessful();

    $order = Order::latest('id')->first();
    $storeId = $order->shopify_order_id;
    expect($storeId)->not->toBeNull();

    // The store created it, but the response was lost before the row was updated.
    $order->forceFill(['status' => 'submitting', 'shopify_order_id' => null, 'order_number' => null, 'shopify_order_name' => null, 'submit_attempts' => 1])->save();

    app(\App\Commerce\OrderService::class)->submit($order->id);

    expect($order->fresh()->status->value)->toBe('confirmed')
        ->and($order->fresh()->shopify_order_id)->toBe($storeId);
});
