<?php

use App\Enums\{OrderStatus, Platform, ShipmentStatus};
use App\Inbox\SavedReplies\ReplyVariables;
use App\Models\{ChannelAccount, Conversation, Customer, CustomerAddress, Order, Shipment, ShippingRate, ShippingZone, ShippingZoneRegion, User};

beforeEach(function () {
    $this->customer = Customer::factory()->create(['name' => 'منى أحمد']);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->conv = Conversation::factory()->for($this->customer)->for($acc, 'channelAccount')->create();
    $this->agent = User::factory()->create(['name' => 'سارة']);
});

it('renders english and arabic variables from the customer, order, shipment and agent', function () {
    $order = Order::factory()->create(['customer_id' => $this->customer->id, 'status' => OrderStatus::Confirmed, 'shopify_order_name' => '#1024']);
    Shipment::factory()->create(['order_id' => $order->id, 'status' => ShipmentStatus::InTransit, 'tracking_number' => 'BST-9']);
    $zone = ShippingZone::factory()->create();
    ShippingZoneRegion::factory()->create(['shipping_zone_id' => $zone->id, 'country_code' => 'EG', 'province_code' => 'GZ']);
    ShippingRate::factory()->create(['shipping_zone_id' => $zone->id, 'title' => 'عادي', 'price' => 65, 'min_order_subtotal' => null, 'max_order_subtotal' => null]);
    CustomerAddress::factory()->create(['customer_id' => $this->customer->id, 'province_code' => 'GZ']);

    $r = app(ReplyVariables::class)->render(
        'أهلاً {الاسم_الأول} ({customer_name}) أوردرك {رقم_الأوردر} {order_status} شحنة {tracking_number} الشحن {سعر_الشحن} معاكي {agent_name}',
        $this->conv, $this->agent,
    );

    expect($r->body)->toBe('أهلاً منى (منى أحمد) أوردرك #1024 في الطريق شحنة BST-9 الشحن 65 EGP معاكي سارة')
        ->and($r->missing)->toBe([]);
});

it('renders missing values as empty and reports them once', function () {
    $r = app(ReplyVariables::class)->render('رقم {order_number} و{رقم_الأوردر} {unknown}', $this->conv, $this->agent);

    expect($r->body)->toBe('رقم  و {unknown}')->and($r->missing)->toBe(['order_number']);
});

it('quotes shipping for the latest address by id, not the default address (spec §2.2 wording)', function () {
    $cairoZone = ShippingZone::factory()->create();
    ShippingZoneRegion::factory()->create(['shipping_zone_id' => $cairoZone->id, 'country_code' => 'EG', 'province_code' => 'C']);
    ShippingRate::factory()->create(['shipping_zone_id' => $cairoZone->id, 'price' => 50, 'min_order_subtotal' => null, 'max_order_subtotal' => null]);
    $gizaZone = ShippingZone::factory()->create();
    ShippingZoneRegion::factory()->create(['shipping_zone_id' => $gizaZone->id, 'country_code' => 'EG', 'province_code' => 'GZ']);
    ShippingRate::factory()->create(['shipping_zone_id' => $gizaZone->id, 'price' => 65, 'min_order_subtotal' => null, 'max_order_subtotal' => null]);

    // The default address is Cairo (older, is_default true) — the customer's
    // *latest* address (highest id, created afterwards) is the non-default
    // Giza one. The spec says "the customer's latest address", not "default".
    CustomerAddress::factory()->create(['customer_id' => $this->customer->id, 'province_code' => 'C', 'is_default' => true]);
    CustomerAddress::factory()->create(['customer_id' => $this->customer->id, 'province_code' => 'GZ', 'is_default' => false]);

    $r = app(ReplyVariables::class)->render('{shipping_fee}', $this->conv, $this->agent);

    expect($r->body)->toBe('65 EGP')->and($r->missing)->toBe([]);
});

it('ignores cancelled and delivered orders when picking the latest open order', function () {
    Order::factory()->create(['customer_id' => $this->customer->id, 'status' => OrderStatus::Cancelled, 'order_number' => 'C-1']);
    $delivered = Order::factory()->create(['customer_id' => $this->customer->id, 'status' => OrderStatus::Confirmed, 'order_number' => 'D-1']);
    Shipment::factory()->create(['order_id' => $delivered->id, 'status' => ShipmentStatus::Delivered]);

    expect(app(ReplyVariables::class)->render('{order_number}', $this->conv, $this->agent)->missing)->toBe(['order_number']);
});
