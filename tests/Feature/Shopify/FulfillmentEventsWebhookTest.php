<?php

use App\Models\Fulfillment;
use App\Models\FulfillmentEvent;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\Mappers\OrderMapper;
use App\Shopify\Webhooks\ParentOrderMissing;
use App\Shopify\Webhooks\ShopifyWebhookProcessor;
use Illuminate\Support\Str;

// The carrier's steps reach the CRM through the fulfillment_events/create webhook (round 2026-10-24).

function signedEventPost($test, array $payload, ?string $id = null)
{
    $body = json_encode($payload);

    return $test->call('POST', '/webhooks/shopify/fulfillment_events-create', [], [], [], [
        'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'sec', true)),
        'HTTP_X-Shopify-Webhook-Id' => $id ?? (string) Str::uuid(),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

beforeEach(function () {
    config(['crm.drivers.commerce' => 'live', 'crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 'sec', 'status' => 'connected']);
    $store = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_order_store.json')), true);
    $this->fulfillment = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_fulfillment.json')), true);
    app(OrderMapper::class)->upsert($store);
    app(OrderMapper::class)->applyFulfillment($this->fulfillment);
    // The fixture's own «confirmed» label status is the first row.
    $this->before = FulfillmentEvent::count();
});

it('verifies, stores and applies a fulfillment event', function () {
    signedEventPost($this, [
        'id' => 8801, 'fulfillment_id' => $this->fulfillment['id'], 'status' => 'out_for_delivery',
        'happened_at' => '2026-09-13T09:00:00+03:00', 'message' => 'With the courier',
    ], 'evt-1')->assertOk();

    expect(WebhookEvent::where('provider', 'shopify')->where('event_type', 'fulfillment_events/create')->value('status'))->toBe('processed')
        ->and(FulfillmentEvent::count())->toBe($this->before + 1)
        ->and(FulfillmentEvent::where('shopify_event_id', '8801')->value('status'))->toBe('out_for_delivery')
        ->and(Order::firstOrFail()->shipment_status)->toBe('out_for_delivery')
        ->and(Fulfillment::firstOrFail()->shipment_status)->toBe('out_for_delivery');

    // The same delivery again changes nothing.
    signedEventPost($this, ['id' => 8801, 'fulfillment_id' => $this->fulfillment['id'], 'status' => 'out_for_delivery', 'happened_at' => '2026-09-13T09:00:00+03:00'], 'evt-1')->assertOk();
    expect(FulfillmentEvent::count())->toBe($this->before + 1);
});

it('refuses an unsigned fulfillment event', function () {
    $this->call('POST', '/webhooks/shopify/fulfillment_events-create', [], [], [], [
        'HTTP_X-Shopify-Hmac-Sha256' => 'nope', 'CONTENT_TYPE' => 'application/json',
    ], json_encode(['id' => 1, 'fulfillment_id' => 1, 'status' => 'delivered']))->assertUnauthorized();

    expect(FulfillmentEvent::count())->toBe($this->before);
});

it('treats an event for a fulfillment not stored yet like any child webhook: retried, then ignored', function () {
    $payload = ['id' => 8802, 'fulfillment_id' => 424242, 'status' => 'delivered', 'happened_at' => '2026-09-13T09:00:00+03:00'];

    expect(fn () => app(ShopifyWebhookProcessor::class)->process('fulfillment_events/create', $payload))->toThrow(ParentOrderMissing::class);
    expect(FulfillmentEvent::count())->toBe($this->before);
});
