<?php

use App\Commerce\OrderStatusResolver;
use App\Commerce\ShopifyDeliveryStep;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;

it('reads the delivery step from the Shopify shipment status only', function (?string $shopify, ?string $deliveredAt, ?ShipmentStatus $step) {
    $order = Order::factory()->make(['shipment_status' => $shopify, 'delivered_at' => $deliveredAt]);

    expect(ShopifyDeliveryStep::of($order))->toBe($step);
})->with([
    'nothing yet' => [null, null, null],
    'label printed' => ['label_printed', null, ShipmentStatus::Created],
    'confirmed' => ['confirmed', null, ShipmentStatus::Created],
    'picked up' => ['picked_up', null, ShipmentStatus::PickedUp],
    'in transit' => ['in_transit', null, ShipmentStatus::InTransit],
    'out for delivery' => ['out_for_delivery', null, ShipmentStatus::OutForDelivery],
    'attempted' => ['attempted_delivery', null, ShipmentStatus::FailedAttempt],
    'failure' => ['failure', null, ShipmentStatus::FailedAttempt],
    'delivered' => ['delivered', null, ShipmentStatus::Delivered],
    'delivered by date' => ['fulfilled', '2026-10-02 10:00:00', ShipmentStatus::Delivered],
    'plain fulfilled' => ['fulfilled', null, null],
    'cancelled label (falls back to fulfillment)' => ['canceled', null, null],
    'voided label' => ['label_voided', null, null],
    'returned (not sent by Shopify today)' => ['returned', null, ShipmentStatus::Returned],
    'unknown' => ['weird', null, null],
]);

it('puts the Shopify step and delivery time on the display status', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled', 'shipment_status' => 'delivered', 'delivered_at' => '2026-10-02 10:00:00']);

    $display = app(OrderStatusResolver::class)->resolve($order);

    expect($display->shipmentStep)->toBe('delivered')
        ->and($display->shipmentAt?->toDateTimeString())->toBe('2026-10-02 10:00:00')
        ->and($display->mismatch)->toBeFalse();
});

it('never flags a carrier mismatch any more (no carrier data), only the Shopify total', function () {
    $resolver = app(OrderStatusResolver::class);
    $cancelledMoving = Order::factory()->create(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'shipment_status' => 'in_transit']);
    $stale = Order::factory()->create(['status' => OrderStatus::Confirmed, 'mismatch' => true, 'mismatch_reason' => 'cancelled_but_in_transit']);

    expect($resolver->refresh($cancelledMoving)->mismatch)->toBeFalse()
        ->and($resolver->refresh($stale)->mismatch)->toBeFalse();
});
