<?php

use App\Commerce\Data\OrderStatusUpdate;
use App\Commerce\FakeCommerceProvider;
use App\Commerce\OrderService;
use App\Enums\{OrderStatus, OrderType, Platform, UserRole};
use App\Events\UserNotified;
use App\Models\{ActivityLog, ChannelAccount, City, Conversation, Customer, Order, Product, ProductVariant, User};
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Event::fake();
    FakeCommerceProvider::$payloads = [];
    FakeCommerceProvider::$forceError = null;
    FakeCommerceProvider::$cancelled = [];
    FakeCommerceProvider::$forceCancelError = null;

    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->customer = Customer::factory()->create(['orders_count' => 0, 'total_spent' => 0]);
    $this->conv = Conversation::factory()->for($this->customer)->for($acc, 'channelAccount')->create(['platform' => Platform::Instagram]);
    $this->variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500, 'shopify_id' => '111']);
    $this->city = City::factory()->create(['shipping_fee' => 60]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::Instagram]);
    $this->sup = User::factory()->create(['role' => UserRole::Supervisor]);
});

function lifecycleOrder(array $o = []): Order
{
    return app(OrderService::class)->create(test()->conv, test()->mod, array_merge([
        'type' => 'cod',
        'items' => [['variant_id' => test()->variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'city_id' => test()->city->id, 'address' => 'شارع النصر'],
    ], $o));
}

it('tags the shopify order with the crm order id', function () {
    $order = lifecycleOrder();

    expect(FakeCommerceProvider::$payloads[0]->noteAttributes['crm_order_id'])->toBe($order->id);
});

it('ignores a paid signal for a cancelled order and alerts supervisors', function () {
    $order = lifecycleOrder(['type' => 'payment_link']);
    app(OrderService::class)->cancel($order, $this->sup);

    $result = app(OrderService::class)->markPaid($order->fresh());

    expect($result->status)->toBe(OrderStatus::Cancelled)
        ->and($result->paid_at)->toBeNull()
        ->and($result->shipment)->toBeNull()
        ->and($this->customer->fresh()->orders_count)->toBe(0)
        ->and(ActivityLog::where('action', 'order.paid_ignored')->where('subject_id', $order->id)->exists())->toBeTrue();

    Event::assertDispatched(UserNotified::class);
});

it('ignores a shopify paid webhook for a failed order', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Failed, 'shopify_order_id' => '9300', 'customer_id' => $this->customer->id]);

    app(OrderService::class)->applyUpdate(new OrderStatusUpdate(
        shopifyOrderId: '9300', draftOrderId: null, financialStatus: 'paid', fulfillmentStatus: null, cancelled: false,
    ));

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->fresh()->paid_at)->toBeNull()
        ->and($order->fresh()->shipment)->toBeNull()
        ->and(ActivityLog::where('action', 'order.paid_ignored')->exists())->toBeTrue();
});

it('cancels the order on the commerce provider after a local cancel', function () {
    $order = lifecycleOrder();

    $cancelled = app(OrderService::class)->cancel($order, $this->sup);

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and(FakeCommerceProvider::$cancelled)->toBe([$order->id]);
});

it('keeps the local cancel and records the error when the provider cancel fails', function () {
    $order = lifecycleOrder();
    FakeCommerceProvider::$forceCancelError = 'Shopify is down';

    $cancelled = app(OrderService::class)->cancel($order, $this->sup);

    $log = ActivityLog::where('action', 'order.cancel_sync_failed')->first();

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($log)->not->toBeNull()
        ->and($log->meta['error'])->toBe('Shopify is down');
});

it('does not call shopify back for a shopify-originated cancel', function () {
    $order = lifecycleOrder();

    app(OrderService::class)->applyUpdate(new OrderStatusUpdate(
        shopifyOrderId: $order->shopify_order_id, draftOrderId: null, financialStatus: null, fulfillmentStatus: null, cancelled: true,
    ));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(FakeCommerceProvider::$cancelled)->toBe([]);
});

it('retries a failed cod order', function () {
    FakeCommerceProvider::$forceError = 'Variant out of stock';
    $order = lifecycleOrder();
    expect($order->status)->toBe(OrderStatus::Failed);

    FakeCommerceProvider::$forceError = null;
    $retried = app(OrderService::class)->retry($order, $this->mod);

    expect($retried->status)->toBe(OrderStatus::Confirmed)
        ->and($retried->shopify_order_id)->not->toBeNull()
        ->and($retried->order_number)->not->toBeNull()
        ->and($retried->shipment)->not->toBeNull()
        ->and($this->customer->fresh()->orders_count)->toBe(1)
        ->and(ActivityLog::where('action', 'order.retried')->where('subject_id', $order->id)->exists())->toBeTrue();
});

it('retries a failed payment link order', function () {
    FakeCommerceProvider::$forceError = 'timeout';
    $order = lifecycleOrder(['type' => 'payment_link']);

    FakeCommerceProvider::$forceError = null;
    $retried = app(OrderService::class)->retry($order, $this->mod);

    expect($retried->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($retried->type)->toBe(OrderType::PaymentLink)
        ->and($retried->invoice_url)->toStartWith('https://')
        ->and($retried->shopify_draft_order_id)->not->toBeNull();
});

it('keeps a failed order failed when the retry fails again', function () {
    FakeCommerceProvider::$forceError = 'timeout';
    $order = lifecycleOrder();

    $retried = app(OrderService::class)->retry($order, $this->mod);

    expect($retried->status)->toBe(OrderStatus::Failed)
        ->and(FakeCommerceProvider::$payloads)->toHaveCount(2);
});

it('refuses to retry an order that has not failed', function () {
    $order = lifecycleOrder();

    app(OrderService::class)->retry($order, $this->mod);
})->throws(ValidationException::class);
