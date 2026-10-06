<?php

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use App\Shopify\Customers\CustomerOrderFlags;

it('computes repeat, open, return and stuck flags', function () {
    $c = Customer::factory()->create();
    Order::factory()->for($c)->create(['status' => OrderStatus::Confirmed]);
    // Fulfilled on Shopify, in transit, no Shopify change for 6 days (> the 5-day default): stuck.
    $open = Order::factory()->for($c)->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled', 'shipment_status' => 'in_transit', 'shopify_updated_at' => now()->subDays(6)]);
    Refund::factory()->for($open)->create();
    app(CustomerOrderFlags::class)->recompute($c);
    expect($c->fresh()->only(['is_repeat', 'has_open_order', 'has_return', 'has_stuck_order']))
        ->toBe(['is_repeat' => true, 'has_open_order' => true, 'has_return' => true, 'has_stuck_order' => true]);
});

// --- Additional coverage -------------------------------------------------------

it('clears flags for delivered, cancelled and fresh orders', function () {
    $c = Customer::factory()->create(['is_repeat' => true, 'has_open_order' => true, 'has_return' => true, 'has_stuck_order' => true]);
    Order::factory()->for($c)->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled', 'shipment_status' => 'delivered', 'shopify_updated_at' => now()->subDays(20)]);
    Order::factory()->for($c)->create(['status' => OrderStatus::Cancelled]);
    app(CustomerOrderFlags::class)->recompute($c);
    expect($c->fresh()->only(['is_repeat', 'has_open_order', 'has_return', 'has_stuck_order']))
        ->toBe(['is_repeat' => false, 'has_open_order' => false, 'has_return' => false, 'has_stuck_order' => false]);
});

it('uses the shopify orders count, refunds as returns, and recent Shopify changes are not stuck', function () {
    $c = Customer::factory()->create(['shopify_orders_count' => 4]);
    $o = Order::factory()->for($c)->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled', 'shipment_status' => 'in_transit', 'shopify_updated_at' => now()->subDay()]);
    app(CustomerOrderFlags::class)->recompute($c);
    expect($c->fresh()->only(['is_repeat', 'has_return', 'has_stuck_order', 'has_open_order']))->toBe(['is_repeat' => true, 'has_return' => false, 'has_stuck_order' => false, 'has_open_order' => true]);

    // Delivered on Shopify ends the order; a refund counts as a return.
    $o->update(['shipment_status' => 'delivered']);
    Refund::factory()->for($o)->create();
    app(CustomerOrderFlags::class)->recompute($c);
    expect($c->fresh()->has_return)->toBeTrue()->and($c->fresh()->has_open_order)->toBeFalse();
});
