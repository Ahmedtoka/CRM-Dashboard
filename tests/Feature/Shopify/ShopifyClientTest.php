<?php

use App\Shopify\Client\{ShopifyClient, ShopifyException, HttpShopifyTransport};
use App\Shopify\Connection\{IntegrationRepository, ShopifyIntegration};
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'tok', 'api_secret' => 's', 'status' => 'connected']);
    $this->sleeps = [];
    $this->client = new ShopifyClient(new HttpShopifyTransport(), app(IntegrationRepository::class), fn (int $s) => $this->sleeps[] = $s);
});

it('sends auth header and returns data', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['shop' => ['name' => 'Demo']], 'extensions' => ['cost' => ['throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 990, 'restoreRate' => 50]]]])]);
    expect($this->client->query('{ shop { name } }'))->toBe(['shop' => ['name' => 'Demo']]);
    Http::assertSent(fn ($r) => $r->hasHeader('X-Shopify-Access-Token', 'tok') && str_contains($r->url(), '/admin/api/2025-07/graphql.json'));
});

it('retries on 429 then succeeds', function () {
    Http::fakeSequence('demo.myshopify.com/*')->push([], 429)->push([], 502)->push(['data' => ['ok' => true]]);
    expect($this->client->query('{ ok }'))->toBe(['ok' => true])->and($this->sleeps)->toBe([2, 4]);
});

it('marks integration error on 401 without retry', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response([], 401)]);
    expect(fn () => $this->client->query('{ shop { name } }'))->toThrow(ShopifyException::class);
    expect(ShopifyIntegration::first()->status)->toBe('error')->and($this->sleeps)->toBe([]);
});

it('throws user_errors from mutations', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['orderCreate' => ['order' => null, 'userErrors' => [['field' => ['order', 'lineItems', '0'], 'message' => 'Not enough inventory']]]]])]);
    try { $this->client->mutate('mutation', [], 'orderCreate'); $this->fail(); }
    catch (ShopifyException $e) { expect($e->kind)->toBe('user_errors')->and($e->userErrors[0]['message'])->toBe('Not enough inventory'); }
});

it('paces requests when throttle budget is low', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['a' => 1], 'extensions' => ['cost' => ['throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 10, 'restoreRate' => 20]]]])]);
    $this->client->query('{ a }');
    $this->client->query('{ a }');
    expect($this->sleeps)->toBe([2]); // ceil((50-10)/20)
});
