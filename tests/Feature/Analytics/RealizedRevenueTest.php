<?php

use App\Analytics\MetricsService;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\AnalyticsDaily;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\User;
use Carbon\CarbonImmutable;

it('counts cod on delivery, links on payment, minus refunds, split by source', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00'));
    $cod = Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::Cod, 'status' => OrderStatus::Confirmed, 'total' => 700, 'source' => 'chat']);
    $s = Shipment::factory()->for($cod)->create(['status' => ShipmentStatus::Delivered]);
    $s->events()->create(['status' => ShipmentStatus::Delivered, 'occurred_at' => now()]);
    Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::Cod, 'status' => OrderStatus::Confirmed, 'total' => 300, 'source' => 'chat']); // not delivered
    $link = Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::PaymentLink, 'status' => OrderStatus::Confirmed, 'financial_status' => 'paid', 'paid_at' => now(), 'total' => 1000, 'source' => 'chat']);
    Refund::factory()->for($link)->create(['amount' => 200, 'shopify_created_at' => now()]);
    $m = app(MetricsService::class)->userMetrics($u, CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-10 23:59:59'));
    expect($m['revenue_realized'])->toBe(1500.0)->and($m['orders_created_count'])->toBe(3)
        ->and($m['orders_delivered'])->toBe(1)->and($m['by_source']['chat']['revenue_realized'])->toBe(1500.0);
});

it('dates revenue by delivery, payment and refund, and reports rates and store orders for the team', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00'));
    $u = User::factory()->create();
    $day = [CarbonImmutable::parse('2026-09-10 00:00:00'), CarbonImmutable::parse('2026-09-10 23:59:59')];

    // Created before the range, delivered inside it.
    $cod = Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::Cod, 'status' => OrderStatus::Confirmed, 'total' => 400, 'created_at' => '2026-09-08 10:00:00']);
    Shipment::factory()->for($cod)->create(['status' => ShipmentStatus::Delivered])
        ->events()->create(['status' => ShipmentStatus::Delivered, 'occurred_at' => '2026-09-10 09:00:00']);

    // Returned inside the range: no revenue.
    $returned = Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::Cod, 'status' => OrderStatus::Confirmed, 'total' => 999, 'created_at' => '2026-09-08 10:00:00']);
    Shipment::factory()->for($returned)->create(['status' => ShipmentStatus::Returned])
        ->events()->create(['status' => ShipmentStatus::Returned, 'occurred_at' => '2026-09-10 11:00:00']);

    // Paid the day before: only its in-range refund counts.
    $link = Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::PaymentLink, 'status' => OrderStatus::Confirmed, 'financial_status' => 'partially_refunded', 'paid_at' => '2026-09-09 10:00:00', 'total' => 500, 'created_at' => '2026-09-09 09:00:00']);
    Refund::factory()->for($link)->create(['amount' => 80, 'shopify_created_at' => '2026-09-10 13:00:00']);

    // Cancelled paid order never counts.
    Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::PaymentLink, 'status' => OrderStatus::Cancelled, 'financial_status' => 'paid', 'paid_at' => '2026-09-10 10:00:00', 'total' => 777, 'created_at' => '2026-09-10 09:00:00']);

    // Store order (no moderator), paid in range.
    Order::factory()->create(['source' => 'store', 'type' => OrderType::PaymentLink, 'status' => OrderStatus::Confirmed, 'financial_status' => 'paid', 'paid_at' => '2026-09-10 15:00:00', 'total' => 250, 'created_at' => '2026-09-10 14:00:00']);

    $m = app(MetricsService::class)->userMetrics($u, ...$day);

    expect($m['revenue_realized'])->toBe(320.0)
        ->and($m['orders_created_count'])->toBe(0)
        ->and($m['orders_created_total'])->toBe(0.0)
        ->and($m['orders_delivered'])->toBe(1)
        ->and($m['orders_returned'])->toBe(1)
        ->and($m['delivery_rate'])->toBe(0.5)
        ->and($m['return_rate'])->toBe(0.5)
        ->and($m['by_source']['store'])->toBe(['created_count' => 0, 'revenue_realized' => 0.0]);

    $team = app(MetricsService::class)->teamMetrics(...$day);

    expect($team['revenue_realized'])->toBe(570.0)
        ->and($team['orders_created_count'])->toBe(1)
        ->and($team['orders_created_total'])->toBe(250.0)
        ->and($team['orders_count'])->toBe(1)
        ->and($team['by_source']['store'])->toBe(['created_count' => 1, 'revenue_realized' => 250.0])
        ->and($team['by_source']['chat'])->toBe(['created_count' => 0, 'revenue_realized' => 320.0])
        ->and($team['delivery_rate'])->toBe(0.5);

    // Long ranges return the same keys and figures for the new metrics.
    $long = app(MetricsService::class)->userMetrics($u, CarbonImmutable::parse('2026-08-01 00:00:00'), CarbonImmutable::parse('2026-09-12 23:59:59'));
    expect(array_keys($long))->toBe(array_keys($m))
        ->and($long['revenue_realized'])->toBe(820.0)
        // Created counts keep orders_count's long-range meaning (rolled-up days + live edges).
        ->and($long['orders_created_count'])->toBe($long['orders_count'])
        ->and($long['by_source']['chat']['created_count'])->toBe(3);
});

it('counts store orders net of refunds once while still subtracting chat refunds', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00'));
    $day = [CarbonImmutable::parse('2026-09-10 00:00:00'), CarbonImmutable::parse('2026-09-10 23:59:59')];

    // Store order: total is Shopify's current_total_price (1000 - 200 refunded).
    $store = Order::factory()->create(['source' => 'store', 'type' => OrderType::PaymentLink, 'status' => OrderStatus::Confirmed, 'financial_status' => 'partially_refunded', 'paid_at' => '2026-09-10 10:00:00', 'total' => 800, 'created_at' => '2026-09-10 09:00:00']);
    Refund::factory()->for($store)->create(['amount' => 200, 'shopify_created_at' => '2026-09-10 11:00:00']);

    // Chat order: total is the CRM total, so its refund is still subtracted.
    $u = User::factory()->create();
    $chat = Order::factory()->create(['created_by_id' => $u->id, 'source' => 'chat', 'type' => OrderType::PaymentLink, 'status' => OrderStatus::Confirmed, 'financial_status' => 'partially_refunded', 'paid_at' => '2026-09-10 10:00:00', 'total' => 1000, 'created_at' => '2026-09-10 09:00:00']);
    Refund::factory()->for($chat)->create(['amount' => 200, 'shopify_created_at' => '2026-09-10 11:00:00']);

    $team = app(MetricsService::class)->teamMetrics(...$day);

    expect($team['by_source']['store']['revenue_realized'])->toBe(800.0)
        ->and($team['by_source']['chat']['revenue_realized'])->toBe(800.0)
        ->and($team['revenue_realized'])->toBe(1600.0)
        ->and(app(MetricsService::class)->userMetrics($u, ...$day)['revenue_realized'])->toBe(800.0);
});

it('reports zero rates without deliveries', function () {
    $u = User::factory()->create();
    $m = app(MetricsService::class)->userMetrics($u, CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-10 23:59:59'));

    expect($m['delivery_rate'])->toBe(0.0)->and($m['return_rate'])->toBe(0.0)->and($m['revenue_realized'])->toBe(0.0);
});

it('rolls up delivered, returned and realized revenue on the Cairo day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-11 12:00:00', 'UTC'));
    $u = User::factory()->create();

    // 2026-09-09 22:30 UTC is 2026-09-10 01:30 in Cairo.
    $cod = Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::Cod, 'status' => OrderStatus::Confirmed, 'total' => 650, 'created_at' => '2026-09-08 10:00:00']);
    Shipment::factory()->for($cod)->create(['status' => ShipmentStatus::Delivered])
        ->events()->create(['status' => ShipmentStatus::Delivered, 'occurred_at' => '2026-09-09 22:30:00']);
    $ret = Order::factory()->create(['created_by_id' => $u->id, 'type' => OrderType::Cod, 'status' => OrderStatus::Confirmed, 'total' => 100, 'created_at' => '2026-09-08 10:00:00']);
    Shipment::factory()->for($ret)->create(['status' => ShipmentStatus::Returned])
        ->events()->create(['status' => ShipmentStatus::Returned, 'occurred_at' => '2026-09-10 10:00:00']);

    $this->artisan('crm:rollup', ['date' => '2026-09-10'])->assertSuccessful();

    $row = AnalyticsDaily::whereDate('date', '2026-09-10')->where('user_id', $u->id)->whereNull('platform')->first();

    expect($row)->not->toBeNull()
        ->and($row->orders_delivered)->toBe(1)
        ->and($row->orders_returned)->toBe(1)
        ->and((float) $row->revenue_realized)->toBe(650.0)
        ->and(AnalyticsDaily::whereDate('date', '2026-09-09')->count())->toBe(0);
});
