<?php

use App\Commerce\Jobs\ProcessShopifyWebhook;
use App\Models\WebhookEvent;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use App\Shopify\Webhooks\ParentOrderMissing;
use App\Shopify\Webhooks\ShopifyWebhookProcessor;

/*
 * A fulfillment/refund for an order the CRM never imported (before the data floor) must not storm retries.
 */

beforeEach(function () {
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
});

function cwEvent(string $topic, string $fixture, int $minutesAgo): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'provider' => 'shopify', 'event_type' => $topic, 'status' => 'pending', 'attempts' => 0, 'error' => null, 'processed_at' => null,
        'payload' => json_decode(file_get_contents(base_path("tests/Fixtures/shopify/{$fixture}")), true),
        'received_at' => now()->subMinutes($minutesAgo), 'created_at' => now()->subMinutes($minutesAgo),
    ]);
}

function cwRun(WebhookEvent $event): void
{
    (new ProcessShopifyWebhook($event->id))->handle(app(ShopifyWebhookProcessor::class), app(IntegrationRepository::class));
}

it('skips a fulfillment or refund whose order is not stored, without an exception', function () {
    $f = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_fulfillment.json')), true);
    $r = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_refund.json')), true);

    expect(app(OrderMapper::class)->applyFulfillment($f))->toBe(MapResult::Skipped)
        ->and(app(OrderMapper::class)->applyRefund($r))->toBe(MapResult::Skipped);
});

it('marks an old child webhook of an unknown order ignored instead of failing it', function () {
    foreach (['fulfillments/create' => 'webhook_fulfillment.json', 'refunds/create' => 'webhook_refund.json'] as $topic => $fixture) {
        $event = cwEvent($topic, $fixture, 20);

        cwRun($event); // no exception: no retries

        expect($event->fresh()->status)->toBe('ignored')
            ->and($event->fresh()->error)->toContain('not in the CRM');
    }
});

it('retries a young child webhook of an unknown order (orders/create may still be on its way)', function () {
    $event = cwEvent('fulfillments/create', 'webhook_fulfillment.json', 1);

    expect(fn () => cwRun($event))->toThrow(ParentOrderMissing::class);
    expect($event->fresh()->status)->toBe('failed');
});
