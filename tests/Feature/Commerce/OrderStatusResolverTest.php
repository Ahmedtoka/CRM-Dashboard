<?php

use App\Commerce\OrderStatusResolver;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Events\IntegrationProgress;
use App\Http\Resources\OrderResource;
use App\Models\ActivityLog;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\Refund;
use App\Shopify\Connection\ShopifyIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;

it('keeps a shopify total mismatch flagged after refresh', function () {
    $resolver = app(OrderStatusResolver::class);

    $stored = Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'mismatch' => true, 'mismatch_reason' => 'shopify_total_differs']);
    // Flagged before the reason was stored: detected from last_error.
    $legacy = Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'mismatch' => true, 'mismatch_reason' => null, 'last_error' => 'إجمالي Shopify 1,050.00 مختلف عن إجمالي الطلب 1,000.00']);
    $unrelated = Order::factory()->create(['status' => OrderStatus::AwaitingPayment, 'mismatch' => true, 'mismatch_reason' => null, 'last_error' => 'Shopify timeout']);

    foreach ([$stored, $legacy] as $order) {
        $fresh = $resolver->refresh($order->fresh());
        expect($fresh->mismatch)->toBeTrue()->and($fresh->mismatch_reason)->toBe('shopify_total_differs');
    }

    expect($resolver->refresh($unrelated->fresh())->mismatch)->toBeFalse();
});

it('re-checks flagged orders hourly and after the orders import stage completes', function () {
    $stale = Order::factory()->create(['status' => OrderStatus::Confirmed, 'mismatch' => true, 'mismatch_reason' => 'cancelled_but_in_transit']);

    $this->artisan('orders:detect-mismatch')->assertSuccessful();
    expect($stale->fresh()->mismatch)->toBeFalse();

    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'orders:detect-mismatch'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');

    Order::query()->whereKey($stale->id)->update(['mismatch' => true, 'mismatch_reason' => 'cancelled_but_in_transit']);
    event(new IntegrationProgress(['stages' => ['orders' => ['status' => 'running', 'run_id' => 7]]]));
    expect($stale->fresh()->mismatch)->toBeTrue();
    event(new IntegrationProgress(['stages' => ['orders' => ['status' => 'completed', 'run_id' => 7]]]));
    expect($stale->fresh()->mismatch)->toBeFalse();
});

it('builds a timeline sorted by time across shopify and crm', function () {
    $t0 = CarbonImmutable::parse('2026-09-10 10:00:00');
    $this->travelTo($t0);
    $order = Order::factory()->create(['source' => OrderSource::Store, 'shopify_order_id' => '5550001', 'status' => OrderStatus::Confirmed, 'financial_status' => 'paid', 'paid_at' => $t0->addHour(), 'created_at' => $t0]);
    Fulfillment::factory()->for($order)->create(['status' => 'success', 'shopify_created_at' => $t0->addHours(2), 'shopify_updated_at' => $t0->addHours(3)]);
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
        'shopify:fulfillment.updated',
        'shopify:refund.created',
    ])
        ->and($timeline[0]['at'])->toBe($t0->toIso8601String())
        ->and($timeline[5]['label_params'])->toBe(['amount' => 50.0, 'currency' => 'EGP'])
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
