<?php

use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShippingZone;
use App\Models\User;
use Illuminate\Support\Str;

it('lists provinces with arabic names and quotes rates', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    $z = ShippingZone::factory()->create();
    $z->regions()->create(['country_code' => 'EG', 'province_code' => 'GZ', 'province_name' => 'Giza']);
    $z->rates()->create(['title' => 'عادي', 'price' => 60]);

    $this->actingAs($u)->getJson('/shipping/provinces')->assertOk()->assertJsonFragment(['code' => 'GZ', 'name' => 'الجيزة']);
    $this->actingAs($u)->getJson('/shipping/quote?province_code=GZ&subtotal=500')->assertOk()->assertJsonPath('data.0.price', '60.00');
});

it('falls back to the shopify province name for an unmapped code', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    $z = ShippingZone::factory()->create();
    $z->regions()->create(['country_code' => 'EG', 'province_code' => 'ZZ', 'province_name' => 'Somewhere']);

    $this->actingAs($u)->getJson('/shipping/provinces')->assertOk()->assertJsonFragment(['code' => 'ZZ', 'name' => 'Somewhere']);
});

it('quotes the default shipping fee when no rate matches', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($u)->getJson('/shipping/quote?subtotal=100')->assertOk()
        ->assertJsonPath('data.0.rate_id', null)
        ->assertJsonPath('data.0.title', 'شحن');
});

it('filters orders by source and mismatch', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $store = Order::factory()->create(['source' => 'store']);
    $bad = Order::factory()->create(['source' => 'chat', 'mismatch' => true]);

    $this->actingAs($admin)->getJson('/orders?source=store')->assertOk()->assertJsonFragment(['id' => $store->id])->assertJsonMissing(['id' => $bad->id]);
    $this->actingAs($admin)->getJson('/orders?mismatch=1')->assertOk()->assertJsonFragment(['id' => $bad->id])->assertJsonMissing(['id' => $store->id]);
});

it('filters orders by financial and fulfillment status', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $paid = Order::factory()->create(['financial_status' => 'paid', 'fulfillment_status' => 'fulfilled']);
    $pending = Order::factory()->create(['financial_status' => 'pending', 'fulfillment_status' => null]);

    $this->actingAs($admin)->getJson('/orders?financial_status=paid')->assertOk()
        ->assertJsonFragment(['id' => $paid->id])->assertJsonMissing(['id' => $pending->id]);
    $this->actingAs($admin)->getJson('/orders?fulfillment_status=fulfilled')->assertOk()
        ->assertJsonFragment(['id' => $paid->id])->assertJsonMissing(['id' => $pending->id]);
});

it('requires an idempotency key to create an order', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $conv = Conversation::factory()->for(Customer::factory())->for($acc, 'channelAccount')->create(['platform' => Platform::Instagram]);
    $variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 100]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->postJson("/inbox/conversations/{$conv->id}/orders", [
        'type' => 'cod', 'items' => [['variant_id' => $variant->id, 'qty' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

    $this->actingAs($sup)->postJson("/inbox/conversations/{$conv->id}/orders", [
        'idempotency_key' => (string) Str::uuid(),
        'type' => 'cod', 'items' => [['variant_id' => $variant->id, 'qty' => 1]],
    ])->assertStatus(201);
});

it('filters orders by shipment step', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $delivered = Order::factory()->create();
    Shipment::factory()->for($delivered)->create(['status' => ShipmentStatus::Delivered]);
    $inTransit = Order::factory()->create();
    Shipment::factory()->for($inTransit)->create(['status' => ShipmentStatus::InTransit]);

    $this->actingAs($admin)->getJson('/orders?shipment_step=delivered')->assertOk()
        ->assertJsonFragment(['id' => $delivered->id])->assertJsonMissing(['id' => $inTransit->id]);
});

/**
 * `?stuck=1` must agree with `CustomerOrderFlags::has_stuck_order` (both now go through
 * `App\Shipping\StuckOrderScope`): exclude cancelled/failed orders, honor the configured
 * cutoff window (default 5 days, no integration in this test), and grant a brand-new
 * shipment with no events yet a grace period instead of flagging it immediately.
 */
it('filters stuck orders consistently with the customer stuck flag', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    // Genuinely stuck: in-transit shipment whose last event is older than the cutoff.
    $stuck = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    $stuckShipment = Shipment::factory()->for($stuck)->create(['status' => ShipmentStatus::InTransit]);
    $stuckShipment->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()->subDays(6)]);

    // A cancelled order must never count as stuck, even with the same stale shipment.
    $cancelled = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    $cancelledShipment = Shipment::factory()->for($cancelled)->create(['status' => ShipmentStatus::InTransit]);
    $cancelledShipment->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()->subDays(6)]);

    // Brand-new shipment with no events yet: within the grace period, not stuck.
    $fresh = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    Shipment::factory()->for($fresh)->create(['status' => ShipmentStatus::Created]);

    // Recently active shipment: not stuck.
    $active = Order::factory()->create(['status' => OrderStatus::Confirmed]);
    $activeShipment = Shipment::factory()->for($active)->create(['status' => ShipmentStatus::InTransit]);
    $activeShipment->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()->subHours(2)]);

    $ids = collect($this->actingAs($admin)->getJson('/orders?stuck=1')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($stuck->id)
        ->not->toContain($cancelled->id)
        ->not->toContain($fresh->id)
        ->not->toContain($active->id);
});

it('excludes archived products and matches on barcode', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    $active = ProductVariant::factory()->for(Product::factory()->state(['status' => 'active']))->create(['barcode' => '6221234567890']);
    ProductVariant::factory()->for(Product::factory()->state(['status' => 'archived']))->create(['title' => 'Archived variant']);

    $byBarcode = $this->actingAs($u)->getJson('/products/search?q=6221234567890')->assertOk()->json('data');
    expect(collect($byBarcode)->pluck('id'))->toContain($active->id);

    $all = $this->actingAs($u)->getJson('/products/search')->assertOk()->json('data');
    expect(collect($all)->pluck('title'))->not->toContain('Archived variant');
});
