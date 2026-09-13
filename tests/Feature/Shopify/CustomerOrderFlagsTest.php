<?php

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Shipment;
use App\Shipping\ShipmentService;
use App\Shopify\Customers\CustomerOrderFlags;

it('computes repeat, open, return and stuck flags', function () {
    $c = Customer::factory()->create();
    Order::factory()->for($c)->create(['status' => OrderStatus::Confirmed]);
    $open = Order::factory()->for($c)->create(['status' => OrderStatus::Confirmed]);
    $s = Shipment::factory()->for($open)->create(['status' => ShipmentStatus::InTransit]);
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()->subDays(6)]);
    Refund::factory()->for($open)->create();
    app(CustomerOrderFlags::class)->recompute($c);
    expect($c->fresh()->only(['is_repeat', 'has_open_order', 'has_return', 'has_stuck_order']))
        ->toBe(['is_repeat' => true, 'has_open_order' => true, 'has_return' => true, 'has_stuck_order' => true]);
});

// --- Additional coverage -------------------------------------------------------

it('clears flags for delivered, cancelled and fresh orders', function () {
    $c = Customer::factory()->create(['is_repeat' => true, 'has_open_order' => true, 'has_return' => true, 'has_stuck_order' => true]);
    $delivered = Order::factory()->for($c)->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled']);
    Shipment::factory()->for($delivered)->create(['status' => ShipmentStatus::Delivered, 'last_event_at' => now()->subDays(20)]);
    Order::factory()->for($c)->create(['status' => OrderStatus::Cancelled]);
    app(CustomerOrderFlags::class)->recompute($c);
    expect($c->fresh()->only(['is_repeat', 'has_open_order', 'has_return', 'has_stuck_order']))
        ->toBe(['is_repeat' => false, 'has_open_order' => false, 'has_return' => false, 'has_stuck_order' => false]);
});

it('uses the shopify orders count and returned shipments', function () {
    $c = Customer::factory()->create(['shopify_orders_count' => 4]);
    $o = Order::factory()->for($c)->create(['status' => OrderStatus::Confirmed]);
    $s = Shipment::factory()->for($o)->create(['status' => ShipmentStatus::InTransit, 'last_event_at' => now()->subDay()]);
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()->subDay()]);
    app(CustomerOrderFlags::class)->recompute($c);
    expect($c->fresh()->only(['is_repeat', 'has_return', 'has_stuck_order']))->toBe(['is_repeat' => true, 'has_return' => false, 'has_stuck_order' => false]);

    app(ShipmentService::class)->applyEvent($s, ShipmentStatus::Returned, 'رجع للمخزن');
    // A returned shipment ends the order: it counts as a return, no longer as open.
    expect($c->fresh()->has_return)->toBeTrue()->and($c->fresh()->has_open_order)->toBeFalse();
});
