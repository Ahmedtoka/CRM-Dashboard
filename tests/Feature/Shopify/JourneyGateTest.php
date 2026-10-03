<?php

use App\Shopify\Client\HttpShopifyTransport;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\SyncQueries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'tok', 'api_secret' => 's', 'status' => 'connected']);
    $this->client = new ShopifyClient(new HttpShopifyTransport, app(IntegrationRepository::class), fn (int $s) => null);
});

$denied = ['errors' => [['message' => 'Access denied for customerJourneySummary field.', 'extensions' => ['code' => 'ACCESS_DENIED']]]];

it('leaves the orders documents untouched by default', function () {
    expect(config('crm.shopify.capture_journey'))->toBeFalse()
        ->and(SyncQueries::paged('orders'))->not->toContain('customerJourneySummary')
        ->and(SyncQueries::bulkRun('orders'))->not->toContain('customerJourneySummary');
});

it('adds the journey field to orders only when the flag is on', function () {
    config(['crm.shopify.capture_journey' => true]);

    expect(SyncQueries::paged('orders'))->toContain('customerJourneySummary')
        ->and(SyncQueries::bulkRun('orders'))->toContain('customerJourneySummary')
        ->and(SyncQueries::paged('products'))->not->toContain('customerJourneySummary')
        ->and(SyncQueries::estimatedWorstCaseCost(SyncQueries::paged('orders')))->toBeLessThanOrEqual(SyncQueries::MAX_QUERY_COST);
});

it('retries without the field when shopify rejects it, keeps the integration connected and skips the field afterwards', function () use ($denied) {
    config(['crm.shopify.capture_journey' => true]);
    Http::fakeSequence('demo.myshopify.com/*')->push($denied)->push(['data' => ['orders' => ['edges' => [], 'pageInfo' => ['hasNextPage' => false]]]]);

    $data = $this->client->query(SyncQueries::paged('orders'), ['cursor' => null, 'query' => '']);

    expect($data)->toHaveKey('orders')
        ->and(ShopifyIntegration::first()->status)->toBe('connected')
        ->and(SyncQueries::paged('orders'))->not->toContain('customerJourneySummary');
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => ! str_contains($r->body(), 'customerJourneySummary') && str_contains($r->body(), 'currentTotalPriceSet'));
});

it('still flags the integration when the token is rejected for a query without the field', function () use ($denied) {
    Http::fake(['demo.myshopify.com/*' => Http::response(['errors' => [['message' => 'nope', 'extensions' => ['code' => 'ACCESS_DENIED']]]])]);

    expect(fn () => $this->client->query('{ shop { name } }'))->toThrow(App\Shopify\Client\ShopifyException::class)
        ->and(ShopifyIntegration::first()->status)->toBe('error');
});

it('reports supported or not supported from shopify:check-journey without flagging the integration', function () use ($denied) {
    Http::fakeSequence('demo.myshopify.com/*')->push(['data' => ['orders' => ['edges' => []]]])->push($denied);
    $this->artisan('shopify:check-journey')->expectsOutput('supported')->assertSuccessful();

    $this->artisan('shopify:check-journey')->expectsOutputToContain('not supported:')->assertFailed();

    expect(ShopifyIntegration::first()->status)->toBe('connected')
        ->and(Cache::has(SyncQueries::JOURNEY_UNSUPPORTED_CACHE_KEY))->toBeFalse();
});
