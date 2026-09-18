<?php

use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\CheckShopifyWebhooks;
use App\Shopify\Jobs\ReconcileShopify;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Task 10 fix round 1: DemoSeeder must refuse outright rather than rely on a
 * narrowly-scoped config override to keep it from ever touching a live
 * Shopify/commerce/channel/AI driver — see DemoSeeder::assertFakeDriversOnly().
 */
it('refuses to run when a driver is not fake', function (string $key) {
    config([$key => 'live']);

    expect(fn () => app(DemoSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'DemoSeeder runs only with fake drivers');

    // Nothing should have been created before the guard fired.
    expect(ShopifyIntegration::count())->toBe(0);
})->with([
    'crm.drivers.channels',
    'crm.drivers.commerce',
    'crm.drivers.shipping',
    'crm.drivers.ai',
    'crm.shopify.driver',
]);

/**
 * Even if a leftover demo integration row somehow coexisted with a live driver
 * (e.g. `.env` misconfigured after a demo run), the scheduled Shopify jobs must
 * never call out for it: both the job-level skip (shop_domain check) and
 * HttpShopifyTransport's own refusal are exercised here — no HTTP request of
 * any kind should be attempted.
 */
it('never calls out for the demo shop domain even when the live driver is configured', function () {
    config(['crm.shopify.driver' => 'live', 'crm.drivers.commerce' => 'live']);
    ShopifyIntegration::create([
        'shop_domain' => ShopifyIntegration::DEMO_SHOP_DOMAIN,
        'access_token' => 'demo-fake-token',
        'api_secret' => 'demo-fake-secret',
        'status' => 'connected',
    ]);
    Http::fake();

    app()->call([new ReconcileShopify, 'handle']);
    app()->call([new CheckShopifyWebhooks, 'handle']);

    Http::assertNothingSent();
});
