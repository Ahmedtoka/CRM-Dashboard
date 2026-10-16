<?php

use App\Commerce\Data\OrderPayload;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Audit A1: a `crm_order_id` note attribute can be set by any shopper (cart
// attributes), so it must never let a storefront payment confirm or cancel an
// unrelated unpaid chat order.

function forgedPost($test, string $topic, array $payload)
{
    $body = json_encode($payload);

    return $test->call('POST', "/webhooks/shopify/{$topic}", [], [], [], [
        'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'sec', true)),
        'HTTP_X-Shopify-Webhook-Id' => (string) Str::uuid(),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function unpaidPaymentLink(): Order
{
    return Order::factory()->create([
        'type' => OrderType::PaymentLink,
        'source' => OrderSource::Chat,
        'status' => OrderStatus::AwaitingPayment,
        'shopify_draft_order_id' => '800123',
        'shopify_order_id' => null,
        'subtotal' => 900,
        'shipping_fee' => 60,
        'total' => 960,
    ]);
}

/** @param array<string, mixed> $extra */
function storefrontPayload(Order $target, array $extra = []): array
{
    return array_replace([
        'id' => 9555,
        'name' => '#9555',
        'financial_status' => 'paid',
        'total_price' => '960.00',
        'current_total_price' => '960.00',
        'tags' => '',
        'note_attributes' => [['name' => 'crm_order_id', 'value' => (string) $target->id]],
    ], $extra);
}

beforeEach(function () {
    config(['crm.drivers.commerce' => 'live', 'crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 'sec', 'status' => 'connected']);
});

it('does not confirm an unrelated payment-link order from a storefront payment carrying its crm_order_id', function () {
    Log::spy();
    $order = unpaidPaymentLink();

    forgedPost($this, 'orders-paid', storefrontPayload($order, ['draft_order_id' => 777000]))->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($fresh->shopify_order_id)->toBeNull()
        ->and($fresh->paid_at)->toBeNull()
        // The storefront order itself is still imported, as a store order.
        ->and(Order::where('shopify_order_id', '9555')->value('source'))->toBe(OrderSource::Store);

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'crm_order_id'));
});

it('does not cancel an unrelated chat order from a storefront cancellation carrying its crm_order_id', function () {
    $order = unpaidPaymentLink();

    forgedPost($this, 'orders-cancelled', storefrontPayload($order, [
        'financial_status' => 'pending',
        'cancelled_at' => now()->toIso8601String(),
    ]))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('still confirms the legit draft conversion (the CRM tag rides on the converted order)', function () {
    $order = unpaidPaymentLink();

    forgedPost($this, 'orders-paid', storefrontPayload($order, ['tags' => 'social-crm, '.OrderPayload::tagFor($order->id)]))->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(OrderStatus::Confirmed)
        ->and($fresh->shopify_order_id)->toBe('9555')
        ->and($fresh->paid_at)->not->toBeNull()
        ->and(Order::count())->toBe(1);
});

it('still confirms the legit draft conversion when the payload names the stored draft id', function () {
    $order = unpaidPaymentLink();

    forgedPost($this, 'orders-paid', storefrontPayload($order, ['draft_order_id' => 800123]))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('does not confirm a partially paid order', function () {
    $order = unpaidPaymentLink();

    forgedPost($this, 'orders-paid', storefrontPayload($order, [
        'financial_status' => 'partially_paid',
        'tags' => OrderPayload::tagFor($order->id),
    ]))->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($fresh->paid_at)->toBeNull()
        // Still linked, so later webhooks find it.
        ->and($fresh->shopify_order_id)->toBe('9555');
});

it('does not confirm when the paid total is below the order total, and logs a warning', function () {
    Log::spy();
    $order = unpaidPaymentLink();

    forgedPost($this, 'orders-paid', storefrontPayload($order, [
        'total_price' => '100.00',
        'current_total_price' => '100.00',
        'tags' => OrderPayload::tagFor($order->id),
    ]))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->fresh()->paid_at)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'total'));
});

it('confirms within a tiny rounding tolerance', function () {
    $order = unpaidPaymentLink();

    forgedPost($this, 'orders-paid', storefrontPayload($order, [
        'total_price' => '959.99',
        'current_total_price' => '959.99',
        'tags' => OrderPayload::tagFor($order->id),
    ]))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('does not let the draft conversion webhook confirm an underpaid draft either', function () {
    $order = unpaidPaymentLink();

    forgedPost($this, 'draft-orders-update', ['id' => 800123, 'order_id' => 9555, 'status' => 'completed', 'total_price' => '100.00'])->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('OrderMapper ignores a crm_order_id on an unlinked payment-link order without proof', function () {
    $order = unpaidPaymentLink();

    expect(app(OrderMapper::class)->upsert(storefrontPayload($order)))->toBe(MapResult::Created);

    expect($order->fresh()->shopify_order_id)->toBeNull()
        ->and(Order::where('shopify_order_id', '9555')->value('source'))->toBe(OrderSource::Store);
});

it('OrderMapper ignores a crm_order_id whose payload draft id differs from the stored one', function () {
    $order = unpaidPaymentLink();

    app(OrderMapper::class)->upsert(storefrontPayload($order, ['draft_order_id' => 777000]));

    expect($order->fresh()->shopify_order_id)->toBeNull();
});
