<?php

use App\Commerce\OrderStatusResolver;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\IntegrationProgress;
use App\Events\UserNotified;
use App\Http\Resources\OrderResource;
use App\Models\ActivityLog;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\User;
use App\Shipping\ShipmentService;
use App\Shopify\Connection\ShopifyIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

it('flags fulfilled in shopify but returned by the carrier after 48 hours', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled', 'financial_status' => 'pending']);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Returned]);
    $s->events()->create(['status' => ShipmentStatus::Returned, 'occurred_at' => now()->subHours(50)]);
    $d = app(OrderStatusResolver::class)->resolve($order->fresh());
    expect($d->mismatch)->toBeTrue()->and($d->mismatchReason)->toBe('fulfilled_but_returned')->and($d->shipmentStep)->toBe('returned');
});

it('does not flag within the grace period', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled']);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Returned]);
    $s->events()->create(['status' => ShipmentStatus::Returned, 'occurred_at' => now()->subHours(10)]);
    expect(app(OrderStatusResolver::class)->resolve($order->fresh())->mismatch)->toBeFalse();
});

it('flags a cancelled order that the carrier is still moving', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::InTransit]);
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()]);
    expect(app(OrderStatusResolver::class)->resolve($order->fresh())->mismatchReason)->toBe('cancelled_but_in_transit');
});

it('flags delivered but unfulfilled after 24 hours and cod delivered unpaid after 72 hours', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'type' => OrderType::Cod, 'shopify_order_id' => '9001', 'fulfillment_status' => null, 'financial_status' => 'pending']);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Delivered]);
    $event = $s->events()->create(['status' => ShipmentStatus::Delivered, 'occurred_at' => now()->subHours(23)]);
    $resolver = app(OrderStatusResolver::class);

    expect($resolver->resolve($order->fresh())->mismatch)->toBeFalse()
        ->and($resolver->resolve($order->fresh(), now()->addHours(2))->mismatchReason)->toBe('delivered_but_unfulfilled');

    $order->update(['fulfillment_status' => 'fulfilled']);
    expect($resolver->resolve($order->fresh(), now()->addHours(40))->mismatch)->toBeFalse()
        ->and($resolver->resolve($order->fresh(), now()->addHours(50))->mismatchReason)->toBe('cod_delivered_unpaid');

    $d = $resolver->resolve($order->fresh());
    expect($d->payment)->toBe('pending')->and($d->fulfillment)->toBe('fulfilled')
        ->and($d->shipmentAt?->equalTo($event->occurred_at))->toBeTrue();
});

it('does not flag a delivered order that was never linked to shopify', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'shopify_order_id' => null]);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Delivered]);
    $s->events()->create(['status' => ShipmentStatus::Delivered, 'occurred_at' => now()->subDays(10)]);

    $d = app(OrderStatusResolver::class)->resolve($order->fresh());
    expect($d->mismatch)->toBeFalse()->and($d->fulfillment)->toBeNull();
});

it('persists the mismatch and notifies supervisors and admins once per reason', function () {
    Event::fake([UserNotified::class]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    User::factory()->create(['role' => UserRole::Moderator]);

    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::InTransit]);
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()]);
    $resolver = app(OrderStatusResolver::class);

    $fresh = $resolver->refresh($order->fresh());
    $resolver->refresh($order->fresh());

    expect($fresh->mismatch)->toBeTrue()
        ->and($fresh->mismatch_reason)->toBe('cancelled_but_in_transit')
        ->and($order->fresh()->mismatch_notified_reasons)->toBe(['cancelled_but_in_transit']);
    Event::assertDispatchedTimes(UserNotified::class, 2);
    Event::assertDispatched(UserNotified::class, fn (UserNotified $e) => $e->userId === $sup->id && $e->type === 'order.mismatch' && $e->data['reason'] === 'cancelled_but_in_transit');
    Event::assertDispatched(UserNotified::class, fn (UserNotified $e) => $e->userId === $admin->id);

    // Same reason again after clearing: no new notification.
    $s->events()->create(['status' => ShipmentStatus::Cancelled, 'occurred_at' => now()->addMinute()]);
    $cleared = $resolver->refresh($order->fresh());
    expect($cleared->mismatch)->toBeFalse()->and($cleared->mismatch_reason)->toBeNull();
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()->addMinutes(2)]);
    $resolver->refresh($order->fresh());
    Event::assertDispatchedTimes(UserNotified::class, 2);

    // A different reason notifies again.
    $order->update(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled']);
    $s->events()->create(['status' => ShipmentStatus::Returned, 'occurred_at' => now()->subHours(49)->addMinutes(3)]);
    $s->events()->create(['status' => ShipmentStatus::Returned, 'occurred_at' => now()->addMinutes(3)]);
    $this->travel(49)->hours();
    $resolver->refresh($order->fresh());
    Event::assertDispatchedTimes(UserNotified::class, 4);
});

it('keeps a shopify total mismatch flagged after refresh, even without a shipment', function () {
    Event::fake([UserNotified::class]);
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $resolver = app(OrderStatusResolver::class);

    $stored = Order::factory()->create(['type' => OrderType::PaymentLink, 'status' => OrderStatus::AwaitingPayment, 'mismatch' => true, 'mismatch_reason' => 'shopify_total_differs']);
    // Flagged by Task 6 before the reason was stored: detected from last_error.
    $legacy = Order::factory()->create(['type' => OrderType::PaymentLink, 'status' => OrderStatus::AwaitingPayment, 'mismatch' => true, 'mismatch_reason' => null, 'last_error' => 'إجمالي Shopify 1,050.00 مختلف عن إجمالي الطلب 1,000.00']);
    $unrelated = Order::factory()->create(['type' => OrderType::PaymentLink, 'status' => OrderStatus::AwaitingPayment, 'mismatch' => true, 'mismatch_reason' => null, 'last_error' => 'Shopify timeout']);

    foreach ([$stored, $legacy] as $order) {
        $fresh = $resolver->refresh($order->fresh());
        expect($fresh->mismatch)->toBeTrue()->and($fresh->mismatch_reason)->toBe('shopify_total_differs');
        $again = $resolver->refresh($order->fresh());
        expect($again->mismatch)->toBeTrue()->and($again->mismatch_reason)->toBe('shopify_total_differs');
    }

    expect($resolver->refresh($unrelated->fresh())->mismatch)->toBeFalse();

    // A carrier mismatch shows in the display reason; the stored total mismatch survives it.
    $stored->update(['status' => OrderStatus::Cancelled]);
    $s = Shipment::factory()->for($stored)->create(['status' => ShipmentStatus::InTransit]);
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()]);
    $withCarrier = $resolver->refresh($stored->fresh());
    expect($withCarrier->mismatch_reason)->toBe('shopify_total_differs')
        ->and($resolver->resolve($stored->fresh())->mismatchReason)->toBe('cancelled_but_in_transit');
    $s->events()->create(['status' => ShipmentStatus::Cancelled, 'occurred_at' => now()->addMinute()]);
    $fresh = $resolver->refresh($stored->fresh());
    expect($fresh->mismatch)->toBeTrue()->and($fresh->mismatch_reason)->toBe('shopify_total_differs');

    // A → B → A (and B again) never repeats a reason already sent for this order.
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()->addMinutes(2)]);
    $resolver->refresh($stored->fresh());
    $s->events()->create(['status' => ShipmentStatus::Cancelled, 'occurred_at' => now()->addMinutes(3)]);
    $resolver->refresh($stored->fresh());

    $sent = Event::dispatched(UserNotified::class, fn (UserNotified $e) => $e->userId === $sup->id && $e->data['order_id'] === $stored->id)
        ->map(fn (array $args) => $args[0]->data['reason'])->values()->all();

    expect($sent)->toBe(['shopify_total_differs', 'cancelled_but_in_transit'])
        ->and($stored->fresh()->mismatch_notified_reasons)->toBe(['shopify_total_differs', 'cancelled_but_in_transit'])
        ->and(Event::dispatched(UserNotified::class, fn (UserNotified $e) => $e->data['order_id'] === $legacy->id)->map(fn (array $args) => $args[0]->data['reason'])->unique()->values()->all())->toBe(['shopify_total_differs']);
});

it('does not notify when mismatch alerts are off', function () {
    Event::fake([UserNotified::class]);
    ShopifyIntegration::create(['shop_domain' => 'd.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected', 'settings' => ['mismatch_alerts' => false]]);
    User::factory()->create(['role' => UserRole::Supervisor]);
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::InTransit]);
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'occurred_at' => now()]);

    expect(app(OrderStatusResolver::class)->refresh($order->fresh())->mismatch)->toBeTrue();
    Event::assertNotDispatched(UserNotified::class);
});

it('recomputes the mismatch when the carrier reports a new event', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Created]);

    app(ShipmentService::class)->applyEvent($s, ShipmentStatus::InTransit);

    expect($order->fresh()->mismatch)->toBeTrue()->and($order->fresh()->mismatch_reason)->toBe('cancelled_but_in_transit');
});

it('detects time-based mismatches hourly and after the orders import stage completes', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'fulfillment_status' => 'fulfilled']);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::Returned, 'last_event_at' => now()->subHours(50)]);
    $s->events()->create(['status' => ShipmentStatus::Returned, 'occurred_at' => now()->subHours(50)]);
    $clean = Order::factory()->create(['status' => OrderStatus::Confirmed, 'mismatch' => true, 'mismatch_reason' => 'cancelled_but_in_transit']);

    $this->artisan('orders:detect-mismatch')->assertSuccessful();

    expect($order->fresh()->mismatch_reason)->toBe('fulfilled_but_returned')
        ->and($clean->fresh()->mismatch)->toBeFalse();

    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'orders:detect-mismatch'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');

    $order->update(['mismatch' => false, 'mismatch_reason' => null]);
    event(new IntegrationProgress(['stages' => ['orders' => ['status' => 'running', 'run_id' => 7]]]));
    expect($order->fresh()->mismatch)->toBeFalse();
    event(new IntegrationProgress(['stages' => ['orders' => ['status' => 'completed', 'run_id' => 7]]]));
    expect($order->fresh()->mismatch)->toBeTrue();
});

it('builds a timeline sorted by time across shopify, shipping and crm', function () {
    $t0 = CarbonImmutable::parse('2026-09-10 10:00:00');
    $this->travelTo($t0);
    $order = Order::factory()->create(['source' => OrderSource::Store, 'shopify_order_id' => '5550001', 'status' => OrderStatus::Confirmed, 'financial_status' => 'paid', 'paid_at' => $t0->addHour(), 'created_at' => $t0]);
    Fulfillment::factory()->for($order)->create(['status' => 'success', 'shopify_created_at' => $t0->addHours(2), 'shopify_updated_at' => $t0->addHours(3)]);
    $s = Shipment::factory()->for($order)->create(['status' => ShipmentStatus::InTransit]);
    $s->events()->create(['status' => ShipmentStatus::InTransit, 'location' => 'Giza', 'occurred_at' => $t0->addMinutes(150)]);
    Refund::factory()->for($order)->create(['amount' => 50, 'shopify_created_at' => $t0->addHours(4)]);
    ActivityLog::factory()->create(['action' => 'order.created', 'subject_type' => (new Order)->getMorphClass(), 'subject_id' => $order->id, 'meta' => ['secret' => 'x'], 'created_at' => $t0->addMinutes(10)]);
    ActivityLog::factory()->create(['action' => 'shipment.updated', 'subject_type' => (new Order)->getMorphClass(), 'subject_id' => $order->id, 'created_at' => $t0->addMinutes(20)]);

    $data = (new OrderResource($order->fresh()))->resolve(request());
    $timeline = collect($data['timeline']);

    expect($timeline->map(fn ($e) => $e['source'].':'.$e['key'])->all())->toBe([
        'shopify:order.created',
        'crm:order.created',
        'shopify:order.paid',
        'shopify:fulfillment.created',
        'shipping:shipment.in_transit',
        'shopify:fulfillment.updated',
        'shopify:refund.created',
    ])
        ->and($timeline[0]['at'])->toBe($t0->toIso8601String())
        ->and($timeline[4]['label_params'])->toBe(['location' => 'Giza'])
        ->and($timeline[6]['label_params'])->toBe(['amount' => 50.0, 'currency' => 'EGP'])
        ->and(json_encode($data['timeline']))->not->toContain('secret')
        ->and($data['source'])->toBe('store')
        ->and($data['display']['payment'])->toBe('paid')
        ->and($data['fulfillments'])->toHaveCount(1)
        ->and($data['refunds'])->toHaveCount(1)
        ->and($data['mismatch'])->toBeFalse()
        ->and($data['mismatch_reason'])->toBeNull();
});

it('links to the shopify admin only for linked orders with an integration', function () {
    $linked = Order::factory()->create(['shopify_order_id' => '5550001']);
    $local = Order::factory()->create(['shopify_order_id' => null]);

    // One request (one list render): "no integration" is cached, not re-queried per row.
    $before = Request::create('/orders');
    expect((new OrderResource($linked))->resolve($before)['shopify_admin_url'])->toBeNull()
        ->and($before->attributes->get('shopify_store_handle'))->toBe('')
        ->and((new OrderResource($linked->fresh()))->resolve($before)['shopify_admin_url'])->toBeNull();

    ShopifyIntegration::create(['shop_domain' => 'demo-store.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);

    $after = Request::create('/orders');
    expect((new OrderResource($linked->fresh()))->resolve($after)['shopify_admin_url'])->toBe('https://admin.shopify.com/store/demo-store/orders/5550001')
        ->and((new OrderResource($local->fresh()))->resolve($after)['shopify_admin_url'])->toBeNull();
});
