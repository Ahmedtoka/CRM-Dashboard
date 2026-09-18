<?php

use App\Analytics\ActivityLogger;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShipmentStatus;
use App\Models\{ActivityLog, Order, Product, Shipment, WebhookEvent};
use App\Shopify\Connection\ShopifyIntegration;
use App\Shipping\ShipmentService;
use Illuminate\Support\Str;

function signedPost($test, string $topic, array $payload, string $secret = 'sec', ?string $id = null)
{
    $body = json_encode($payload);

    return $test->call('POST', "/webhooks/shopify/{$topic}", [], [], [], array_filter([
        'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'HTTP_X-Shopify-Webhook-Id' => $id ?? (string) Str::uuid(),
        'CONTENT_TYPE' => 'application/json',
    ]), $body);
}

beforeEach(function () {
    config(['crm.drivers.commerce' => 'live', 'crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 'sec', 'status' => 'connected']);
});

it('verifies with the integration secret, dedupes and routes to mappers', function () {
    $p = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_product.json')), true);
    signedPost($this, 'products-create', $p, 'sec', 'w-1')->assertOk();
    signedPost($this, 'products-create', $p, 'sec', 'w-1')->assertOk();
    signedPost($this, 'products-create', $p, 'wrong')->assertUnauthorized();
    expect(Product::count())->toBe(1)->and(WebhookEvent::where('provider', 'shopify')->count())->toBe(1);
});

it('routes store orders and cancellations', function () {
    $o = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_order_store.json')), true);
    signedPost($this, 'orders-create', $o)->assertOk();
    signedPost($this, 'orders-cancelled', array_replace($o, ['cancelled_at' => '2030-01-01T00:00:00Z', 'updated_at' => '2030-01-01T00:00:00Z']))->assertOk();
    expect(Order::where('shopify_order_id', (string) $o['id'])->value('status')?->value)->toBe('cancelled');
});

it('disconnects on app/uninstalled', function () {
    signedPost($this, 'app-uninstalled', ['id' => 1])->assertOk();
    expect(ShopifyIntegration::first()->status)->toBe('disconnected');
});

it('confirms an awaiting-payment chat order via markPaid when orders/paid arrives', function () {
    $order = Order::factory()->create([
        'type' => OrderType::PaymentLink,
        'status' => OrderStatus::AwaitingPayment,
        'shopify_draft_order_id' => '800123',
        'shopify_order_id' => null,
    ]);

    signedPost($this, 'orders-paid', [
        'id' => 9100,
        'name' => '#1100',
        'financial_status' => 'paid',
        'note_attributes' => [
            ['name' => 'crm_conversation_id', 'value' => '1'],
            ['name' => 'crm_order_id', 'value' => (string) $order->id],
        ],
    ])->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(OrderStatus::Confirmed)
        ->and($fresh->shopify_order_id)->toBe('9100')
        ->and($fresh->paid_at)->not->toBeNull()
        ->and($fresh->shipment)->not->toBeNull();
});

it('confirms a chat order exactly once with one shipment and one stats increment, whichever order webhook carries the paid signal first', function () {
    // Case A: orders/create already carries the paid signal, before the dedicated orders/paid webhook.
    $orderA = Order::factory()->create(['type' => OrderType::Cod, 'status' => OrderStatus::AwaitingPayment, 'shopify_order_id' => null]);
    $orderA->customer->update(['orders_count' => 0, 'total_spent' => 0]);
    $payloadA = ['id' => 9200, 'name' => '#9200', 'financial_status' => 'paid', 'note_attributes' => [['name' => 'crm_order_id', 'value' => (string) $orderA->id]]];

    signedPost($this, 'orders-create', $payloadA, 'sec', 'a-create')->assertOk();
    signedPost($this, 'orders-paid', $payloadA, 'sec', 'a-paid')->assertOk();

    $freshA = $orderA->fresh();
    expect($freshA->status)->toBe(OrderStatus::Confirmed)
        ->and(Shipment::where('order_id', $orderA->id)->count())->toBe(1)
        ->and($freshA->customer->orders_count)->toBe(1);

    // Case B: orders/paid arrives first, then a later orders/updated for the same order.
    $orderB = Order::factory()->create(['type' => OrderType::Cod, 'status' => OrderStatus::AwaitingPayment, 'shopify_order_id' => null]);
    $orderB->customer->update(['orders_count' => 0, 'total_spent' => 0]);
    $payloadB = ['id' => 9300, 'name' => '#9300', 'financial_status' => 'paid', 'note_attributes' => [['name' => 'crm_order_id', 'value' => (string) $orderB->id]]];

    signedPost($this, 'orders-paid', $payloadB, 'sec', 'b-paid')->assertOk();
    signedPost($this, 'orders-updated', $payloadB, 'sec', 'b-updated')->assertOk();

    $freshB = $orderB->fresh();
    expect($freshB->status)->toBe(OrderStatus::Confirmed)
        ->and(Shipment::where('order_id', $orderB->id)->count())->toBe(1)
        ->and($freshB->customer->orders_count)->toBe(1);
});

it('cancels a confirmed chat order via webhook, reversing stats and recording the shipment/activity events, idempotently', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'shopify_order_id' => '9400', 'financial_status' => 'paid']);
    $order->customer->update(['orders_count' => 1, 'total_spent' => $order->total]);
    app(ShipmentService::class)->createFor($order);
    expect($order->shipment)->not->toBeNull();

    $payload = ['id' => 9400, 'name' => '#9400', 'cancelled_at' => now()->toIso8601String(), 'cancel_reason' => 'customer'];

    signedPost($this, 'orders-cancelled', $payload, 'sec', 'cancel-1')->assertOk();

    $fresh = $order->fresh(['shipment', 'customer']);
    expect($fresh->status)->toBe(OrderStatus::Cancelled)
        ->and($fresh->customer->orders_count)->toBe(0)
        ->and((float) $fresh->customer->total_spent)->toBe(0.0)
        ->and($fresh->shipment->status)->toBe(ShipmentStatus::Cancelled)
        ->and(ActivityLog::where('action', ActivityLogger::ORDER_CANCELLED)->where('subject_id', $order->id)->count())->toBe(1);

    // A second delivery of the same webhook is a no-op (order already cancelled).
    signedPost($this, 'orders-cancelled', $payload, 'sec', 'cancel-2')->assertOk();
    expect(ActivityLog::where('action', ActivityLogger::ORDER_CANCELLED)->where('subject_id', $order->id)->count())->toBe(1)
        ->and($order->fresh()->customer->orders_count)->toBe(0);
});

it('stores but does not process webhooks while the integration is disconnected, except app/uninstalled', function () {
    ShopifyIntegration::first()->update(['status' => 'disconnected']);
    $p = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_product.json')), true);

    signedPost($this, 'products-create', $p, 'sec', 'ignored-1')->assertOk();

    expect(Product::count())->toBe(0)
        ->and(WebhookEvent::where('dedupe_key', 'ignored-1')->value('status'))->toBe('ignored');

    signedPost($this, 'app-uninstalled', ['id' => 1], 'sec', 'uninstall-1')->assertOk();
    expect(WebhookEvent::where('dedupe_key', 'uninstall-1')->value('status'))->toBe('processed');
});

it('stores an unknown topic as ignored without processing it', function () {
    signedPost($this, 'carts-update', ['id' => 1], 'sec', 'unknown-1')->assertOk();
    expect(WebhookEvent::where('dedupe_key', 'unknown-1')->value('status'))->toBe('ignored');
});

// --- Final fix wave I5 ---

it('does not mark paid from a stale orders/paid payload', function () {
    $order = Order::factory()->create(['type' => OrderType::PaymentLink, 'status' => OrderStatus::AwaitingPayment, 'shopify_order_id' => '9500']);
    $order->forceFill(['shopify_updated_at' => '2030-01-02 00:00:00'])->save();

    signedPost($this, 'orders-paid', ['id' => 9500, 'name' => '#9500', 'financial_status' => 'paid', 'updated_at' => '2030-01-01T00:00:00Z'])->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->fresh()->paid_at)->toBeNull();
});

it('ignores a stale orders/paid after a cancel without a paid_ignored alert', function () {
    $order = Order::factory()->create(['type' => OrderType::PaymentLink, 'status' => OrderStatus::Cancelled, 'shopify_order_id' => '9600']);
    $order->forceFill(['shopify_updated_at' => '2030-01-02 00:00:00', 'cancelled_at' => '2030-01-02 00:00:00'])->save();

    signedPost($this, 'orders-paid', ['id' => 9600, 'name' => '#9600', 'financial_status' => 'paid', 'updated_at' => '2030-01-01T00:00:00Z'])->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(ActivityLog::where('action', ActivityLogger::ORDER_PAID_IGNORED)->count())->toBe(0);
});

it('does not cancel from a stale orders/cancelled payload', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'shopify_order_id' => '9700', 'financial_status' => 'paid']);
    $order->forceFill(['shopify_updated_at' => '2030-01-02 00:00:00'])->save();

    signedPost($this, 'orders-cancelled', ['id' => 9700, 'name' => '#9700', 'cancelled_at' => '2030-01-01T00:00:00Z', 'updated_at' => '2030-01-01T00:00:00Z'])->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(ActivityLog::where('action', ActivityLogger::ORDER_CANCELLED)->where('subject_id', $order->id)->count())->toBe(0);
});

it('still marks paid from a newer orders/paid payload', function () {
    $order = Order::factory()->create(['type' => OrderType::PaymentLink, 'status' => OrderStatus::AwaitingPayment, 'shopify_order_id' => '9800']);
    $order->forceFill(['shopify_updated_at' => '2030-01-01 00:00:00'])->save();

    signedPost($this, 'orders-paid', ['id' => 9800, 'name' => '#9800', 'financial_status' => 'paid', 'updated_at' => '2030-01-02T00:00:00Z'])->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->fresh()->paid_at)->not->toBeNull();
});
