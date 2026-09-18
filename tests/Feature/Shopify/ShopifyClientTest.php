<?php

use App\Shopify\Client\HttpShopifyTransport;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'tok', 'api_secret' => 's', 'status' => 'connected']);
    $this->sleeps = [];
    $this->client = new ShopifyClient(new HttpShopifyTransport, app(IntegrationRepository::class), fn (int $s) => $this->sleeps[] = $s);
});

it('sends auth header and returns data', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['shop' => ['name' => 'Demo']], 'extensions' => ['cost' => ['throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 990, 'restoreRate' => 50]]]])]);
    expect($this->client->query('{ shop { name } }'))->toBe(['shop' => ['name' => 'Demo']]);
    Http::assertSent(fn ($r) => $r->hasHeader('X-Shopify-Access-Token', 'tok') && str_contains($r->url(), '/admin/api/2025-07/graphql.json'));
});

it('sends empty variables as a json object, not an array', function () {
    // Real Shopify answers `"variables": []` with "Invalid variables parameter".
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['shop' => ['name' => 'Demo']]])]);
    $this->client->query('{ shop { name } }');
    Http::assertSent(fn ($r) => str_contains($r->body(), '"variables":{}'));
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
    try {
        $this->client->mutate('mutation', [], 'orderCreate');
        $this->fail();
    } catch (ShopifyException $e) {
        expect($e->kind)->toBe('user_errors')->and($e->userErrors[0]['message'])->toBe('Not enough inventory');
    }
});

it('paces requests when throttle budget is low', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['a' => 1], 'extensions' => ['cost' => ['throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 10, 'restoreRate' => 20]]]])]);
    $this->client->query('{ a }');
    $this->client->query('{ a }');
    expect($this->sleeps)->toBe([2]); // ceil((50-10)/20)
});

it('never resends a mutation after a 5xx because shopify may have executed it', function () {
    Http::fakeSequence('demo.myshopify.com/*')
        ->push([], 502)
        ->push(['data' => ['orderCreate' => ['order' => ['id' => 'gid://shopify/Order/1'], 'userErrors' => []]]]);

    try {
        $this->client->mutate('mutation orderCreate { orderCreate { order { id } } }', [], 'orderCreate');
        $this->fail('expected a transport exception');
    } catch (ShopifyException $e) {
        expect($e->kind)->toBe('transport');
    }

    Http::assertSentCount(1);
    expect($this->sleeps)->toBe([]);
});

it('treats a mutation document sent through query() as a mutation', function () {
    Http::fakeSequence('demo.myshopify.com/*')->push([], 503)->push(['data' => ['orderCancel' => ['orderCancelUserErrors' => []]]]);

    expect(fn () => $this->client->query('mutation orderCancel { orderCancel { job { id } } }'))->toThrow(ShopifyException::class);
    Http::assertSentCount(1);
});

it('still retries a throttled mutation', function () {
    Http::fakeSequence('demo.myshopify.com/*')
        ->push([], 429)
        ->push(['data' => ['orderCreate' => ['order' => ['id' => 'gid://shopify/Order/1'], 'userErrors' => []]]]);

    $data = $this->client->mutate('mutation orderCreate { orderCreate { order { id } } }', [], 'orderCreate');

    expect($data['orderCreate']['order']['id'])->toBe('gid://shopify/Order/1')->and($this->sleeps)->toBe([2]);
    Http::assertSentCount(2);
});

it('raises non-throttle graphql errors as graphql without retrying', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['errors' => [['message' => "Field 'foo' doesn't exist on type 'Order'"]]])]);

    try {
        $this->client->query('{ order { foo } }');
        $this->fail('expected a graphql exception');
    } catch (ShopifyException $e) {
        expect($e->kind)->toBe('graphql')->and($e->getMessage())->toBe("Field 'foo' doesn't exist on type 'Order'");
    }

    Http::assertSentCount(1);
    expect($this->sleeps)->toBe([]);
});

/**
 * Task 10 fix round 1: independent of any driver/job-level guard, the live
 * transport itself must never reach the demo/seeder shop domain — the last
 * line of defense if a demo `ShopifyIntegration` row is ever read by a live
 * process.
 */
it('refuses to call the demo shop domain even when it is the connected integration', function () {
    ShopifyIntegration::query()->delete();
    ShopifyIntegration::create(['shop_domain' => ShopifyIntegration::DEMO_SHOP_DOMAIN, 'access_token' => 'tok', 'api_secret' => 's', 'status' => 'connected']);
    Http::fake();

    try {
        $this->client->query('{ shop { name } }');
        $this->fail('expected a transport exception');
    } catch (ShopifyException $e) {
        expect($e->kind)->toBe('transport')->and($e->getMessage())->toContain(ShopifyIntegration::DEMO_SHOP_DOMAIN);
    }

    Http::assertNothingSent();
});
