<?php

use App\Models\ShopifySyncRun;
use App\Models\ShopifyWebhookSubscription;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Webhooks\WebhookRegistrar;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['crm.shopify.driver' => 'live', 'app.url' => 'https://crm-staging.example.com']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
});

/**
 * The default `crm.shopify.api_version` (2025-07) reads/writes the webhook
 * destination via `endpoint { ... on WebhookHttpEndpoint { callbackUrl } }` /
 * `{ callbackUrl, format }` — the `uri` field only exists from 2025-10 on.
 * These fakes always answer in that 2025-07 shape.
 */
function fakeShopifyListResponse(array $nodes)
{
    return Http::response(['data' => ['webhookSubscriptions' => ['nodes' => $nodes]]]);
}

function fakeShopifyCreateResponse(?string $id, array $userErrors = [])
{
    return Http::response(['data' => ['webhookSubscriptionCreate' => [
        'webhookSubscription' => $id === null ? null : ['id' => $id],
        'userErrors' => $userErrors,
    ]]]);
}

it('registers every configured topic with the dashed callback url', function () {
    Http::fake(['demo.myshopify.com/*' => function ($request) {
        $body = $request->data();

        if (str_contains($body['query'] ?? '', 'query webhookSubscriptions')) {
            return fakeShopifyListResponse([]);
        }

        return fakeShopifyCreateResponse('gid://shopify/WebhookSubscription/'.random_int(1, 99999));
    }]);

    $created = app(WebhookRegistrar::class)->register();

    expect($created)->toHaveCount(count(config('crm.shopify.webhook_topics')))
        ->and(ShopifyWebhookSubscription::where('topic', 'orders/paid')->value('callback_url'))->toBe('https://crm-staging.example.com/webhooks/shopify/orders-paid');
    Http::assertSent(fn ($r) => str_contains($r['variables']['topic'] ?? '', 'INVENTORY_LEVELS_UPDATE'));
});

it('adopts an existing remote subscription that already has the right topic and uri', function () {
    $callbackUrl = 'https://crm-staging.example.com/webhooks/shopify/orders-paid';

    Http::fake(['demo.myshopify.com/*' => function ($request) use ($callbackUrl) {
        $body = $request->data();

        if (str_contains($body['query'] ?? '', 'query webhookSubscriptions')) {
            return fakeShopifyListResponse([
                ['id' => 'gid://shopify/WebhookSubscription/9', 'topic' => 'ORDERS_PAID', 'endpoint' => ['callbackUrl' => $callbackUrl]],
            ]);
        }

        return fakeShopifyCreateResponse('gid://shopify/WebhookSubscription/'.random_int(1, 99999));
    }]);

    $created = app(WebhookRegistrar::class)->register();

    expect($created)->not->toContain('orders/paid')
        ->and(ShopifyWebhookSubscription::where('topic', 'orders/paid')->value('shopify_subscription_id'))->toBe('gid://shopify/WebhookSubscription/9');
    Http::assertNotSent(fn ($r) => ($r->data()['variables']['topic'] ?? null) === 'ORDERS_PAID'
        && str_contains($r->data()['query'] ?? '', 'webhookSubscriptionCreate'));
});

it('deletes and recreates a subscription whose remote uri no longer matches ours', function () {
    Http::fake(['demo.myshopify.com/*' => function ($request) {
        $body = $request->data();
        $query = $body['query'] ?? '';

        if (str_contains($query, 'query webhookSubscriptions')) {
            return fakeShopifyListResponse([
                ['id' => 'gid://shopify/WebhookSubscription/9', 'topic' => 'ORDERS_PAID', 'endpoint' => ['callbackUrl' => 'https://old.example.com/hook']],
            ]);
        }

        if (str_contains($query, 'webhookSubscriptionDelete')) {
            return Http::response(['data' => ['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => 'gid://shopify/WebhookSubscription/9', 'userErrors' => []]]]);
        }

        return fakeShopifyCreateResponse('gid://shopify/WebhookSubscription/'.random_int(1, 99999));
    }]);

    $created = app(WebhookRegistrar::class)->register();

    expect($created)->toContain('orders/paid')
        ->and(ShopifyWebhookSubscription::where('topic', 'orders/paid')->value('callback_url'))->toBe('https://crm-staging.example.com/webhooks/shopify/orders-paid');
    Http::assertSent(fn ($r) => str_contains($r->data()['query'] ?? '', 'webhookSubscriptionDelete')
        && ($r->data()['variables']['id'] ?? null) === 'gid://shopify/WebhookSubscription/9');
});

it('records a partial run when one topic fails to register, ok when none do', function () {
    Http::fake(['demo.myshopify.com/*' => function ($request) {
        $body = $request->data();
        $query = $body['query'] ?? '';

        if (str_contains($query, 'query webhookSubscriptions')) {
            return fakeShopifyListResponse([]);
        }

        if (($body['variables']['topic'] ?? null) === 'ORDERS_PAID') {
            return fakeShopifyCreateResponse(null, [['field' => ['topic'], 'message' => 'Invalid topic for this app']]);
        }

        return fakeShopifyCreateResponse('gid://shopify/WebhookSubscription/'.random_int(1, 99999));
    }]);

    $reRegistered = app(WebhookRegistrar::class)->check();

    $run = ShopifySyncRun::where('type', 'webhook_health')->latest('id')->first();
    expect($run->status)->toBe('partial')
        ->and($run->errors)->not->toBeEmpty()
        ->and($reRegistered)->not->toContain('orders/paid')
        ->and($reRegistered)->toHaveCount(count(config('crm.shopify.webhook_topics')) - 1);
});

it('adopts a topic after an already-been-taken userError by re-listing once', function () {
    $callbackUrl = 'https://crm-staging.example.com/webhooks/shopify/orders-paid';
    $listCalls = 0;

    Http::fake(['demo.myshopify.com/*' => function ($request) use (&$listCalls, $callbackUrl) {
        $body = $request->data();
        $query = $body['query'] ?? '';

        if (str_contains($query, 'query webhookSubscriptions')) {
            $listCalls++;

            // Missing on the first (pre-create) list; present by the second
            // (post "already taken") re-list.
            return $listCalls >= 2
                ? fakeShopifyListResponse([['id' => 'gid://shopify/WebhookSubscription/9', 'topic' => 'ORDERS_PAID', 'endpoint' => ['callbackUrl' => $callbackUrl]]])
                : fakeShopifyListResponse([]);
        }

        if (($body['variables']['topic'] ?? null) === 'ORDERS_PAID') {
            return fakeShopifyCreateResponse(null, [['field' => ['webhookSubscription', 'callbackUrl'], 'message' => 'Address for this topic has already been taken']]);
        }

        return fakeShopifyCreateResponse('gid://shopify/WebhookSubscription/'.random_int(1, 99999));
    }]);

    $created = app(WebhookRegistrar::class)->register();

    expect($created)->not->toContain('orders/paid')
        ->and($listCalls)->toBe(2)
        ->and(ShopifyWebhookSubscription::where('topic', 'orders/paid')->value('shopify_subscription_id'))->toBe('gid://shopify/WebhookSubscription/9');
});

it('re-registers topics missing on shopify and records a webhook_health sync run', function () {
    Http::fake(['demo.myshopify.com/*' => function ($request) {
        $body = $request->data();

        if (str_contains($body['query'] ?? '', 'query webhookSubscriptions')) {
            return fakeShopifyListResponse([]);
        }

        return fakeShopifyCreateResponse('gid://shopify/WebhookSubscription/'.random_int(1, 99999));
    }]);

    $reRegistered = app(WebhookRegistrar::class)->check();

    expect($reRegistered)->toHaveCount(count(config('crm.shopify.webhook_topics')))
        ->and(ShopifySyncRun::where('type', 'webhook_health')->where('status', 'ok')->exists())->toBeTrue();
});

it('deletes every stored subscription remotely and locally', function () {
    ShopifyWebhookSubscription::create(['topic' => 'orders/paid', 'shopify_subscription_id' => 'gid://shopify/WebhookSubscription/9', 'callback_url' => 'x']);
    Http::fake(['demo.myshopify.com/*' => fn () => Http::response(['data' => ['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => 'gid://shopify/WebhookSubscription/9', 'userErrors' => []]]])]);

    app(WebhookRegistrar::class)->removeAll();

    expect(ShopifyWebhookSubscription::count())->toBe(0);
});
