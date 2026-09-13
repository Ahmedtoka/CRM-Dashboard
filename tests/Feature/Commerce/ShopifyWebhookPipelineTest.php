<?php

use App\Commerce\Jobs\ProcessShopifyWebhook;
use App\Enums\{OrderStatus, OrderType};
use App\Models\{Order, WebhookEvent};
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['crm.drivers.commerce' => 'live', 'crm.shopify.webhook_secret' => 's3']);
});

function postShopify(string $topic, array $body, ?string $webhookId = null)
{
    $raw = json_encode($body);
    $server = [
        'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $raw, 's3', true)),
        'CONTENT_TYPE' => 'application/json',
    ];

    if ($webhookId !== null) {
        $server['HTTP_X-Shopify-Webhook-Id'] = $webhookId;
    }

    return test()->call('POST', "/webhooks/shopify/{$topic}", [], [], [], $server, $raw);
}

it('stores shopify webhooks as deduped events processed on the commerce queue', function () {
    Queue::fake();
    $body = ['id' => 9001, 'financial_status' => 'paid', 'name' => '#1001'];

    postShopify('orders-paid', $body, 'wh-1')->assertOk();
    postShopify('orders-paid', $body + ['updated_at' => 'later'], 'wh-1')->assertOk();

    $event = WebhookEvent::where('provider', 'shopify')->sole();

    expect($event->event_type)->toBe('orders/paid')
        ->and($event->dedupe_key)->toBe('wh-1')
        ->and($event->status)->toBe('received');

    Queue::assertPushedOn('commerce', ProcessShopifyWebhook::class);
    Queue::assertPushed(ProcessShopifyWebhook::class, 1);
});

it('dedupes by body hash when there is no webhook id header', function () {
    Queue::fake();
    $body = ['id' => 9002, 'financial_status' => 'paid'];

    postShopify('orders-paid', $body)->assertOk();
    postShopify('orders-paid', $body)->assertOk();

    expect(WebhookEvent::where('provider', 'shopify')->count())->toBe(1);
    Queue::assertPushed(ProcessShopifyWebhook::class, 1);
});

it('still rejects shopify webhooks with a bad signature', function () {
    $raw = json_encode(['id' => 1]);

    $this->call('POST', '/webhooks/shopify/orders-paid', [], [], [], ['HTTP_X-Shopify-Hmac-Sha256' => 'bad', 'CONTENT_TYPE' => 'application/json'], $raw)
        ->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0);
});

it('confirms a payment link when orders/paid arrives before draft_orders/update', function () {
    $order = Order::factory()->create([
        'type' => OrderType::PaymentLink,
        'status' => OrderStatus::AwaitingPayment,
        'shopify_draft_order_id' => '800123',
        'shopify_order_id' => null,
    ]);

    postShopify('orders-paid', [
        'id' => 9100,
        'name' => '#1100',
        'financial_status' => 'paid',
        'note_attributes' => [
            ['name' => 'crm_conversation_id', 'value' => '1'],
            ['name' => 'crm_order_id', 'value' => (string) $order->id],
        ],
    ], 'wh-paid')->assertOk();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(OrderStatus::Confirmed)
        ->and($fresh->shopify_order_id)->toBe('9100')
        // orders/paid now routes through OrderMapper (Task 4): it stores the bare
        // number in order_number and keeps the "#"-prefixed display form in
        // shopify_order_name, replacing the old ad-hoc pipeline's single field.
        ->and($fresh->order_number)->toBe('1100')
        ->and($fresh->shopify_order_name)->toBe('#1100')
        ->and($fresh->paid_at)->not->toBeNull();

    postShopify('draft-orders-update', ['id' => 800123, 'order_id' => 9100, 'status' => 'completed'], 'wh-draft')->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(WebhookEvent::where('provider', 'shopify')->pluck('status')->unique()->all())->toBe(['processed']);
});
