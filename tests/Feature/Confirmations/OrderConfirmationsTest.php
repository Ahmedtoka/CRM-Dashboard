<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Jobs\SendOrderConfirmation;
use App\Confirmations\OrderConfirmations;
use App\Confirmations\SkipReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderConfirmation;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendOrderConfirmation::class]);
    app(ConfirmationSettings::class)->save(['enabled' => true, 'delay_minutes' => 3]);
    $this->whatsapp = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected']);
});

function ocStoreOrder(array $attrs = []): Order
{
    return Order::factory()->create(array_merge([
        'source' => OrderSource::Store,
        'status' => OrderStatus::Confirmed,
        'shopify_order_id' => (string) fake()->unique()->numberBetween(1000, 999999),
        'shipping_phone' => '01012345678',
        'billing_phone' => null,
        'placed_at' => now()->subMinutes(2),
    ], $attrs));
}

function ocSkipReason(Order $order, bool $test = false): ?string
{
    $c = app(OrderConfirmations::class)->onOrderCreated($order, $test);

    expect($c)->not->toBeNull()->and($c->status)->toBe(OrderConfirmationStatus::Skipped);
    Queue::assertNotPushed(SendOrderConfirmation::class);

    return $c->skip_reason;
}

it('schedules a confirmation and dispatches the delayed send', function () {
    $this->freezeSecond();
    $order = ocStoreOrder(['shipping_phone' => '010 1234 5678']);

    $c = app(OrderConfirmations::class)->onOrderCreated($order);

    expect($c->status)->toBe(OrderConfirmationStatus::Scheduled)
        ->and($c->skip_reason)->toBeNull()
        ->and($c->phone_e164)->toBe('+201012345678')
        ->and($c->channel_account_id)->toBe($this->whatsapp->id)
        ->and($c->customer_id)->toBe($order->customer_id)
        ->and($c->send_at->equalTo(now()->addMinutes(3)))->toBeTrue();

    Queue::assertPushedOn('outbound', SendOrderConfirmation::class, fn ($job) => $job->confirmationId === $c->id
        && $job->delay->equalTo(now()->addMinutes(3)));
});

it('creates nothing at all while the feature is off', function () {
    app(ConfirmationSettings::class)->save(['enabled' => false]);

    expect(app(OrderConfirmations::class)->onOrderCreated(ocStoreOrder()))->toBeNull()
        ->and(OrderConfirmation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps one confirmation per order and does not schedule twice', function () {
    $order = ocStoreOrder();

    $first = app(OrderConfirmations::class)->onOrderCreated($order);
    $second = app(OrderConfirmations::class)->onOrderCreated($order);

    expect($second->id)->toBe($first->id)->and(OrderConfirmation::count())->toBe(1);
    Queue::assertPushed(SendOrderConfirmation::class, 1);
});

it('falls back to the billing phone, then the customer phone', function () {
    $billing = ocStoreOrder(['shipping_phone' => 'n/a', 'billing_phone' => '+20 11 1234 5678']);
    $customer = Customer::factory()->create(['phone' => '٠١٢١٢٣٤٥٦٧٨']);
    $fromCustomer = ocStoreOrder(['shipping_phone' => null, 'customer_id' => $customer->id]);

    expect(app(OrderConfirmations::class)->onOrderCreated($billing)->phone_e164)->toBe('+201112345678')
        ->and(app(OrderConfirmations::class)->onOrderCreated($fromCustomer)->phone_e164)->toBe('+201212345678');
});

it('skips an order without a usable Egyptian mobile', function () {
    $customer = Customer::factory()->create(['phone' => '+966501234567']);

    expect(ocSkipReason(ocStoreOrder(['shipping_phone' => '0223456789', 'customer_id' => $customer->id])))
        ->toBe(SkipReason::NoPhone->value);
});

it('skips an order older than the max age (imports and backfills)', function () {
    app(ConfirmationSettings::class)->save(['max_order_age_minutes' => 120]);

    expect(ocSkipReason(ocStoreOrder(['placed_at' => now()->subMinutes(121)])))->toBe(SkipReason::TooOld->value);
});

it('skips an order placed before the data floor', function () {
    config(['crm.data_floor' => now()->addDay()->toDateString()]);

    expect(ocSkipReason(ocStoreOrder()))->toBe(SkipReason::BeforeFloor->value);
});

it('skips a Shopify test order', function () {
    expect(ocSkipReason(ocStoreOrder(), test: true))->toBe(SkipReason::TestOrder->value);
});

it('skips a load-test order', function () {
    $order = ocStoreOrder(['is_load_test' => true]);

    expect(ocSkipReason($order))->toBe(SkipReason::TestOrder->value);
});

it('skips an order taken in a test channel chat', function () {
    $testAccount = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'test']);
    $conversation = Conversation::factory()->create(['channel_account_id' => $testAccount->id]);
    $order = ocStoreOrder(['conversation_id' => $conversation->id]);

    expect(ocSkipReason($order))->toBe(SkipReason::TestOrder->value);
});

it('skips a cancelled order', function () {
    expect(ocSkipReason(ocStoreOrder(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])))->toBe(SkipReason::Cancelled->value)
        ->and(ocSkipReason(ocStoreOrder(['cancelled_at' => now()])))->toBe(SkipReason::Cancelled->value);
});

it('skips a chat order unless the owner opted in', function () {
    expect(ocSkipReason(ocStoreOrder(['source' => OrderSource::Chat])))->toBe(SkipReason::ChatOrder->value);

    app(ConfirmationSettings::class)->save(['skip_chat_orders' => false]);

    expect(app(OrderConfirmations::class)->onOrderCreated(ocStoreOrder(['source' => OrderSource::Chat]))->status)
        ->toBe(OrderConfirmationStatus::Scheduled);
});

it('skips when no WhatsApp account is connected', function () {
    $this->whatsapp->update(['status' => 'disconnected']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'status' => 'connected']);
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'test', 'status' => 'connected']);
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected', 'is_load_test' => true]);

    expect(ocSkipReason(ocStoreOrder()))->toBe(SkipReason::NoWhatsAppChannel->value);
});

it('never uses a fake WhatsApp account in production', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(ocSkipReason(ocStoreOrder()))->toBe(SkipReason::NoWhatsAppChannel->value);

        $live = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected']);

        expect(app(OrderConfirmations::class)->onOrderCreated(ocStoreOrder())->channel_account_id)->toBe($live->id);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('never uses a live WhatsApp account outside production by default', function () {
    // A local copy restored from production keeps the real WhatsApp number: a local worker must not message customers.
    $this->whatsapp->update(['status' => 'disconnected']);
    $live = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected']);

    expect(ocSkipReason(ocStoreOrder()))->toBe(SkipReason::NoWhatsAppChannel->value)
        ->and(OrderConfirmations::accountUsable($live))->toBeFalse();
});

it('keeps using the fake account outside production while a live one is on file', function () {
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected']);

    expect(app(OrderConfirmations::class)->onOrderCreated(ocStoreOrder())->channel_account_id)->toBe($this->whatsapp->id);
});

it('prefers a live WhatsApp account outside production only when explicitly allowed', function () {
    config(['crm.confirmations.allow_live_outside_production' => true]);
    $live = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected']);

    expect(app(OrderConfirmations::class)->onOrderCreated(ocStoreOrder())->channel_account_id)->toBe($live->id)
        ->and(OrderConfirmations::accountUsable($live))->toBeTrue();
});

it('skips a Shopify POS order', function () {
    $c = app(OrderConfirmations::class)->onOrderCreated(ocStoreOrder(), false, 'pos');

    expect($c->status)->toBe(OrderConfirmationStatus::Skipped)->and($c->skip_reason)->toBe(SkipReason::PosOrder->value);
    Queue::assertNotPushed(SendOrderConfirmation::class);
});

it('confirms online-store, draft and app orders', function (?string $source) {
    expect(app(OrderConfirmations::class)->onOrderCreated(ocStoreOrder(), false, $source)->status)
        ->toBe(OrderConfirmationStatus::Scheduled);
})->with(['web', 'shopify_draft_order', 'iphone', null]);
