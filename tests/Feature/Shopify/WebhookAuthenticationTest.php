<?php

use App\Models\WebhookEvent;
use App\Shopify\Connection\ShopifyIntegration;

/**
 * Final fix wave C1: the Shopify webhook endpoint authenticates deliveries
 * itself (integration api_secret → crm.shopify.webhook_secret → none),
 * independent of the commerce driver.
 */
function shopifyWebhookCall($test, string $topic, array $payload, ?string $secret = null)
{
    $body = json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_X-Shopify-Hmac-Sha256'] = base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    return $test->call('POST', "/webhooks/shopify/{$topic}", [], [], [], $server, $body);
}

beforeEach(function () {
    config([
        'crm.drivers.commerce' => 'fake',
        'crm.shopify.driver' => 'fake',
        'crm.shopify.webhook_secret' => null,
        'crm.allow_fake_webhooks' => false,
    ]);
});

it('rejects an unsigned delivery outside local/testing with the fake driver and no secret', function () {
    app()['env'] = 'production';
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => null, 'status' => 'connected']);

    shopifyWebhookCall($this, 'app-uninstalled', ['id' => 1])->assertUnauthorized();

    expect(ShopifyIntegration::first()->status)->toBe('connected')
        ->and(WebhookEvent::count())->toBe(0);
});

it('rejects an unsigned delivery outside local/testing when the integration has a secret', function () {
    app()['env'] = 'production';
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 'sec', 'status' => 'connected']);

    shopifyWebhookCall($this, 'app-uninstalled', ['id' => 1])->assertUnauthorized();

    expect(ShopifyIntegration::first()->status)->toBe('connected');
});

it('accepts a validly signed delivery with the fake driver outside local/testing', function () {
    app()['env'] = 'production';
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 'sec', 'status' => 'connected']);

    shopifyWebhookCall($this, 'app-uninstalled', ['id' => 1], 'sec')->assertOk();

    expect(ShopifyIntegration::first()->status)->toBe('disconnected')
        ->and(WebhookEvent::where('provider', 'shopify')->value('status'))->toBe('processed');
});

it('falls back to the configured webhook secret when the integration has none', function () {
    app()['env'] = 'production';
    config(['crm.shopify.webhook_secret' => 'cfg']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => null, 'status' => 'disconnected']);

    shopifyWebhookCall($this, 'products-delete', ['id' => 5], 'cfg')->assertOk();
    shopifyWebhookCall($this, 'products-delete', ['id' => 6], 'wrong')->assertUnauthorized();
});

it('uses the integration secret even when the integration is disconnected', function () {
    app()['env'] = 'production';
    config(['crm.shopify.webhook_secret' => 'cfg']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => null, 'api_secret' => 'sec', 'status' => 'disconnected']);

    shopifyWebhookCall($this, 'app-uninstalled', ['id' => 1], 'cfg')->assertUnauthorized();
    shopifyWebhookCall($this, 'app-uninstalled', ['id' => 1], 'sec')->assertOk();
});

it('rejects a wrong signature in local when a secret is configured', function () {
    app()['env'] = 'local';
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 'sec', 'status' => 'connected']);

    shopifyWebhookCall($this, 'app-uninstalled', ['id' => 1], 'wrong')->assertUnauthorized();
    shopifyWebhookCall($this, 'app-uninstalled', ['id' => 1])->assertUnauthorized();

    expect(ShopifyIntegration::first()->status)->toBe('connected');
});

it('accepts an unsigned delivery in local when no secret is configured (demo/simulator)', function () {
    app()['env'] = 'local';
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => null, 'status' => 'connected']);

    shopifyWebhookCall($this, 'app-uninstalled', ['id' => 1])->assertOk();

    expect(ShopifyIntegration::first()->status)->toBe('disconnected');
});

it('accepts an unsigned delivery on another env only when fake webhooks are allowed and no secret is configured', function () {
    app()['env'] = 'production';
    config(['crm.allow_fake_webhooks' => true]);

    shopifyWebhookCall($this, 'products-delete', ['id' => 5])->assertOk();

    config(['crm.shopify.webhook_secret' => 'cfg']);
    shopifyWebhookCall($this, 'products-delete', ['id' => 6])->assertUnauthorized();
});
