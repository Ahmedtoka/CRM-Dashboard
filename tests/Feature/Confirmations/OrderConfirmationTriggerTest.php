<?php

use App\Commerce\Jobs\ProcessShopifyWebhook;
use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Events\ShopifyOrderCreated;
use App\Confirmations\Jobs\SendOrderConfirmation;
use App\Confirmations\Listeners\ScheduleOrderConfirmation;
use App\Confirmations\OrderConfirmations;
use App\Confirmations\SkipReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\WebhookEvent;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Webhooks\ShopifyWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['crm.drivers.commerce' => 'live', 'crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 'sec', 'status' => 'connected']);
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected']);
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    Queue::fake([SendOrderConfirmation::class]);
});

function ocPayload(array $overrides = []): array
{
    $now = now()->toIso8601String();

    return array_replace([
        'id' => 7700001,
        'name' => '#7701',
        'order_number' => 7701,
        'created_at' => $now,
        'updated_at' => $now,
        'processed_at' => $now,
        'cancelled_at' => null,
        'currency' => 'EGP',
        'financial_status' => 'pending',
        'gateway' => 'Cash on Delivery (COD)',
        'total_price' => '950.00',
        'phone' => null,
        'customer' => ['id' => 88001, 'first_name' => 'Nour', 'last_name' => 'Ali', 'phone' => null, 'email' => 'nour@example.com'],
        'shipping_address' => ['name' => 'Nour Ali', 'phone' => '0101 234 5678', 'city' => 'Nasr City', 'province' => 'Cairo', 'address1' => '12 St'],
        'billing_address' => ['phone' => null],
        'line_items' => [],
        'test' => false,
    ], $overrides);
}

function ocProcess(string $topic, array $payload): void
{
    $event = WebhookEvent::factory()->create([
        'provider' => 'shopify', 'event_type' => $topic, 'status' => 'pending', 'attempts' => 0,
        'payload' => $payload, 'received_at' => now(),
    ]);

    (new ProcessShopifyWebhook($event->id))->handle(app(ShopifyWebhookProcessor::class), app(IntegrationRepository::class));

    expect($event->fresh()->status)->toBe('processed');
}

it('schedules a confirmation for a new order from orders/create and queues the delayed send', function () {
    $this->freezeSecond();

    ocProcess('orders/create', ocPayload());

    $order = Order::where('shopify_order_id', '7700001')->firstOrFail();
    $c = OrderConfirmation::where('order_id', $order->id)->firstOrFail();

    expect($c->status)->toBe(OrderConfirmationStatus::Scheduled)
        ->and($c->phone_e164)->toBe('+201012345678')
        ->and($c->customer_id)->toBe($order->customer_id);
    Queue::assertPushedOn('outbound', SendOrderConfirmation::class, fn ($job) => $job->confirmationId === $c->id
        && $job->delay->equalTo(now()->addMinutes(3)));
});

it('announces orders/create on a queued listener, never inline', function () {
    Event::fake([ShopifyOrderCreated::class]);

    ocProcess('orders/create', ocPayload(['test' => true]));

    Event::assertDispatched(ShopifyOrderCreated::class, fn ($e) => $e->test === true);
    Event::assertListening(ShopifyOrderCreated::class, ScheduleOrderConfirmation::class);
    expect(new ScheduleOrderConfirmation(app(OrderConfirmations::class)))
        ->toBeInstanceOf(ShouldQueue::class);
});

it('records a Shopify test checkout as skipped', function () {
    ocProcess('orders/create', ocPayload(['test' => true]));

    expect(OrderConfirmation::sole()->skip_reason)->toBe(SkipReason::TestOrder->value);
});

it('never starts a confirmation from orders/updated or orders/paid', function () {
    ocProcess('orders/updated', ocPayload());
    ocProcess('orders/paid', ocPayload(['financial_status' => 'paid', 'updated_at' => now()->addMinute()->toIso8601String()]));

    expect(Order::where('shopify_order_id', '7700001')->exists())->toBeTrue()
        ->and(OrderConfirmation::count())->toBe(0);
    Queue::assertNotPushed(SendOrderConfirmation::class);
});

it('still confirms when orders/updated overtook orders/create', function () {
    $later = now()->addMinute()->toIso8601String();
    ocProcess('orders/updated', ocPayload(['updated_at' => $later]));
    ocProcess('orders/create', ocPayload()); // stale copy: the mapper skips it, the order exists

    expect(OrderConfirmation::sole()->status)->toBe(OrderConfirmationStatus::Scheduled);
});

it('creates nothing from orders/create while the feature is off', function () {
    app(ConfirmationSettings::class)->save(['enabled' => false]);

    ocProcess('orders/create', ocPayload());

    expect(OrderConfirmation::count())->toBe(0);
    Queue::assertNotPushed(SendOrderConfirmation::class);
});

it('keeps one confirmation when orders/create is delivered twice', function () {
    ocProcess('orders/create', ocPayload());
    ocProcess('orders/create', ocPayload(['updated_at' => now()->addMinute()->toIso8601String()]));

    expect(OrderConfirmation::count())->toBe(1);
    Queue::assertPushed(SendOrderConfirmation::class, 1);
});

it('skips an old order that only now reaches the webhook', function () {
    $old = now()->subHours(3)->toIso8601String();

    ocProcess('orders/create', ocPayload(['created_at' => $old, 'processed_at' => $old]));

    expect(OrderConfirmation::sole()->skip_reason)->toBe(SkipReason::TooOld->value);
});

it('never fails the webhook when scheduling the confirmation throws', function () {
    Event::listen(ShopifyOrderCreated::class, fn () => throw new RuntimeException('boom'));

    ocProcess('orders/create', ocPayload()); // asserts the event is processed

    expect(Order::where('shopify_order_id', '7700001')->exists())->toBeTrue();
});
