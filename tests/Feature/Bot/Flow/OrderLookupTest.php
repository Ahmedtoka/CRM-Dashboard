<?php

use App\Bot\Flow\Orders\FakeOmsClient;
use App\Bot\Flow\Orders\OmsClient;
use App\Bot\Flow\Orders\OmsStatus;
use App\Bot\Flow\Orders\OrderLookup;
use App\Bot\Flow\Orders\OrderSnapshot;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Enums\ShipmentStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;

beforeEach(fn () => app()->instance(OmsClient::class, new FakeOmsClient));

it('finds an order by number and prefers the OMS status', function () {
    $o = Order::factory()->create(['shopify_order_name' => '#1234', 'order_number' => '1234']);
    FakeOmsClient::$statuses['1234'] = new OmsStatus('on_the_way', now()->toImmutable(), 'Bosta', null);
    $r = app(OrderLookup::class)->find(Conversation::factory()->create(), ['order_ref' => '1234']);
    expect($r['status'])->toBe('found')->and($r['snapshots'][0]->statusKey)->toBe('on_the_way')->and($r['snapshots'][0]->source)->toBe('oms')
        ->and($r['snapshots'][0]->orderId)->toBe($o->id)->and($r['snapshots'][0]->number)->toBe('#1234');
});

it('falls back to shopify state when OMS does not know the order', function () {
    Order::factory()->create(['shopify_order_name' => '#2000', 'order_number' => '2000', 'fulfillment_status' => 'fulfilled']);
    $r = app(OrderLookup::class)->find(Conversation::factory()->create(), ['order_ref' => '#2000']);
    expect($r['snapshots'][0]->statusKey)->toBe('shipped')->and($r['snapshots'][0]->source)->toBe('shopify');
});

it('finds by phone, lists several open orders, and reports missing details', function () {
    Order::factory()->count(2)->create(['shipping_phone' => '+201001234567']);
    $lookup = app(OrderLookup::class);
    expect($lookup->find(Conversation::factory()->create(), ['phone' => '01001234567'])['status'])->toBe('multiple')
        ->and($lookup->find(Conversation::factory()->create(), [])['status'])->toBe('missing_details')
        ->and($lookup->find(Conversation::factory()->create(), ['order_ref' => '999999'])['status'])->toBe('not_found');
});

it('uses shopify data and reports when the OMS throws', function () {
    Exceptions::fake();
    app()->instance(OmsClient::class, new class implements OmsClient
    {
        public function status(string $orderNumber): ?OmsStatus
        {
            throw new RuntimeException('oms down');
        }
    });
    Order::factory()->create(['order_number' => '3000', 'shopify_order_name' => '#3000']);

    $r = app(OrderLookup::class)->find(Conversation::factory()->create(), ['order_ref' => '3000']);

    expect($r['status'])->toBe('found')->and($r['snapshots'][0]->statusKey)->toBe('confirmed')->and($r['snapshots'][0]->source)->toBe('shopify_fallback');
    Exceptions::assertReported(RuntimeException::class);
});

it('maps the local shipment step when the OMS has no status', function (string $step, string $key) {
    $o = Order::factory()->create(['order_number' => '4000']);
    Shipment::factory()->for($o)->create(['status' => $step]);

    expect(app(OrderLookup::class)->find(Conversation::factory()->create(), ['order_ref' => '4000'])['snapshots'][0]->statusKey)->toBe($key);
})->with([
    ['created', 'confirmed'], ['picked_up', 'shipped'], ['in_transit', 'shipped'], ['out_for_delivery', 'on_the_way'],
    ['delivered', 'delivered'], ['returned', 'returned'], ['cancelled', 'cancelled'], ['failed_attempt', 'on_the_way'],
]);

it('flags a failed delivery attempt on the snapshot', function () {
    $o = Order::factory()->create(['order_number' => '4100']);
    Shipment::factory()->for($o)->create(['status' => ShipmentStatus::FailedAttempt]);

    expect(app(OrderLookup::class)->find(Conversation::factory()->create(), ['order_ref' => '4100'])['snapshots'][0]->failedAttempt)->toBeTrue();
});

it('marks cancelled orders and takes the tracking link from the latest fulfillment', function () {
    Order::factory()->create(['order_number' => '5000', 'cancelled_at' => now()]);
    $o = Order::factory()->create(['order_number' => '5001', 'fulfillment_status' => 'partial']);
    Fulfillment::factory()->for($o)->create(['tracking_url' => 'https://t.test/old', 'shopify_created_at' => now()->subDays(2)]);
    Fulfillment::factory()->for($o)->create(['tracking_url' => 'https://t.test/new', 'shopify_created_at' => now()->subDay()]);

    $lookup = app(OrderLookup::class);
    $shipped = $lookup->find(Conversation::factory()->create(['customer_id' => $o->customer_id]), ['order_ref' => '5001'])['snapshots'][0];

    expect($lookup->find(Conversation::factory()->create(), ['order_ref' => '5000'])['snapshots'][0]->statusKey)->toBe('cancelled')
        ->and($shipped->statusKey)->toBe('shipped')
        ->and($shipped->trackingUrl)->toBe('https://t.test/new');
});

it('shows an order number lookup the tracking link only when the asker owns the order', function () {
    $o = Order::factory()->create(['order_number' => '5002', 'fulfillment_status' => 'partial', 'shipping_phone' => '01001234567']);
    Fulfillment::factory()->for($o)->create(['tracking_url' => 'https://t.test/5002', 'shopify_created_at' => now()->subDay()]);
    $lookup = app(OrderLookup::class);

    $stranger = $lookup->find(Conversation::factory()->create(), ['order_ref' => '5002'])['snapshots'][0];
    $samePhone = $lookup->find(Conversation::factory()->create(), ['order_ref' => '5002', 'phone' => '+20 100 123 4567'])['snapshots'][0];
    $owner = $lookup->find(Conversation::factory()->create(['customer_id' => $o->customer_id]), ['order_ref' => '5002'])['snapshots'][0];

    expect($stranger->statusKey)->toBe('shipped')->and($stranger->trackingUrl)->toBeNull()
        ->and($samePhone->trackingUrl)->toBe('https://t.test/5002')
        ->and($owner->trackingUrl)->toBe('https://t.test/5002');
});

it('never returns another customer\'s orders for a phone or email', function () {
    $mine = Customer::factory()->create(['phone' => '01001234567', 'normalized_phone' => '+201001234567', 'email' => 'Mona@Example.test']);
    Order::factory()->for($mine)->create(['order_number' => '6000', 'shipping_phone' => '01119999999']);
    Order::factory()->create(['order_number' => '6001', 'shipping_phone' => '+201224444444']);

    $lookup = app(OrderLookup::class);
    $byCustomerPhone = $lookup->find(Conversation::factory()->create(), ['phone' => '+20 100 123 4567']);
    $byEmail = $lookup->find(Conversation::factory()->create(), ['email' => 'mona@example.test']);

    expect($byCustomerPhone['status'])->toBe('found')->and($byCustomerPhone['snapshots'][0]->number)->toBe('#6000')
        ->and($byEmail['status'])->toBe('found')->and($byEmail['snapshots'][0]->number)->toBe('#6000')
        ->and($lookup->find(Conversation::factory()->create(), ['phone' => '01005555555'])['status'])->toBe('not_found')
        ->and($lookup->find(Conversation::factory()->create(), ['email' => 'other@example.test'])['status'])->toBe('not_found');
});

it('picks the one open order for a phone even when delivered ones exist', function () {
    $open = Order::factory()->create(['order_number' => '7001', 'shipping_phone' => '01001234567', 'created_at' => now()->subDays(3)]);
    $done = Order::factory()->create(['order_number' => '7002', 'shipping_phone' => '01001234567', 'created_at' => now()->subDay()]);
    Shipment::factory()->for($done)->create(['status' => ShipmentStatus::Delivered]);

    $r = app(OrderLookup::class)->find(Conversation::factory()->create(), ['phone' => '01001234567']);

    expect($r['status'])->toBe('found')->and($r['snapshots'])->toHaveCount(1)->and($r['snapshots'][0]->orderId)->toBe($open->id);
});

it('takes the governorate from the province code, then the city, then the understood governorate', function () {
    Order::factory()->create(['order_number' => '8001', 'shipping_province_code' => 'GZ', 'shipping_city' => 'Dokki']);
    Order::factory()->create(['order_number' => '8002', 'shipping_province_code' => null, 'shipping_city' => 'الإسكندرية']);
    Order::factory()->create(['order_number' => '8003', 'shipping_province_code' => null, 'shipping_city' => 'Zamalek']);
    Order::factory()->create(['order_number' => '8004', 'shipping_province_code' => 'ASN', 'shipping_city' => 'Aswan']);

    $gov = fn (string $n, array $extra = []) => app(OrderLookup::class)->find(Conversation::factory()->create(), ['order_ref' => $n] + $extra)['snapshots'][0]->governorate;

    expect($gov('8001'))->toBe('الجيزة')
        ->and($gov('8002'))->toBe('الإسكندرية')
        ->and($gov('8003', ['governorate' => 'القاهره']))->toBe('القاهرة')
        ->and($gov('8004'))->not->toBeIn(['القاهرة', 'الجيزة', 'الإسكندرية']);
});

it('prefers the most recent non-cancelled order and returns a cancelled one only when all are', function () {
    $delivered = Order::factory()->create(['order_number' => '9201', 'shipping_phone' => '01001234567', 'created_at' => now()->subDays(5)]);
    Shipment::factory()->for($delivered)->create(['status' => ShipmentStatus::Delivered]);
    Order::factory()->create(['order_number' => '9202', 'shipping_phone' => '01001234567', 'cancelled_at' => now(), 'created_at' => now()->subDay()]);
    Order::factory()->create(['order_number' => '9203', 'shipping_phone' => '01112223334', 'cancelled_at' => now(), 'created_at' => now()->subDays(3)]);
    $latestCancelled = Order::factory()->create(['order_number' => '9204', 'shipping_phone' => '01112223334', 'cancelled_at' => now(), 'created_at' => now()->subDay()]);

    $lookup = app(OrderLookup::class);

    expect($lookup->find(Conversation::factory()->create(), ['phone' => '01001234567'])['snapshots'][0]->orderId)->toBe($delivered->id)
        ->and($lookup->find(Conversation::factory()->create(), ['phone' => '01112223334'])['snapshots'][0]->orderId)->toBe($latestCancelled->id);
});

it('stops calling the OMS after the first failure and never asks about finished orders', function () {
    Exceptions::fake();
    $oms = new class implements OmsClient
    {
        public int $calls = 0;

        public function status(string $orderNumber): ?OmsStatus
        {
            $this->calls++;

            throw new RuntimeException('oms down');
        }
    };
    app()->instance(OmsClient::class, $oms);
    Order::factory()->count(3)->create(['shipping_phone' => '01001234567']);
    $done = Order::factory()->create(['order_number' => '9100', 'shipping_phone' => '01001234567']);
    Shipment::factory()->for($done)->create(['status' => ShipmentStatus::Delivered]);

    $r = app(OrderLookup::class)->find(Conversation::factory()->create(), ['phone' => '01001234567']);

    expect($oms->calls)->toBe(1)
        ->and($r['status'])->toBe('multiple')
        ->and(collect($r['snapshots'])->pluck('source')->unique()->all())->toBe(['shopify_fallback']);

    $oms->calls = 0;
    expect(app(OrderLookup::class)->find(Conversation::factory()->create(), ['order_ref' => '9100'])['snapshots'][0]->statusKey)->toBe('delivered')
        ->and($oms->calls)->toBe(0);
});

it('uses the store placed time of an imported order for delay and the cancel window', function () {
    $placed = now()->subDays(20);
    Order::factory()->create(['order_number' => '9300', 'shipping_province_code' => 'C', 'placed_at' => $placed]);

    $snap = app(OrderLookup::class)->find(Conversation::factory()->create(), ['order_ref' => '9300'])['snapshots'][0];

    expect($snap->placedAt->timestamp)->toBe($placed->timestamp)
        ->and((new OrderStatusText)->isDelayed($snap, CarbonImmutable::now()))->toBeTrue()
        ->and(app(OrderLookup::class)->cancelWindowLeftMinutes($snap, CarbonImmutable::now()))->toBe(0);
});

it('counts the cancel/edit window down from two hours', function () {
    $lookup = app(OrderLookup::class);
    $now = CarbonImmutable::parse('2026-09-15 12:00');
    $s = fn (string $placed) => new OrderSnapshot(1, '#1', CarbonImmutable::parse($placed), 'shopify', 'confirmed', null, null);

    expect($lookup->cancelWindowLeftMinutes($s('2026-09-15 11:30'), $now))->toBe(90)
        ->and($lookup->cancelWindowLeftMinutes($s('2026-09-15 09:00'), $now))->toBe(0);
});
