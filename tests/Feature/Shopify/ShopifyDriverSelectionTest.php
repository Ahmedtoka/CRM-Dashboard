<?php

use App\Commerce\Contracts\CommerceProvider;
use App\Commerce\FakeCommerceProvider;
use App\Commerce\ShopifyCommerceProvider;
use App\Shopify\Client\FakeShopifyTransport;
use App\Shopify\Client\HttpShopifyTransport;
use App\Shopify\Client\ShopifyTransport;

/**
 * Final fix wave I6: driver selection matches strictly — only the exact value
 * 'live' reaches a real store; anything else (typos, casing, 'off') is fake.
 */
it('uses the fake shopify transport for any driver value other than live', function (string $driver) {
    config(['crm.shopify.driver' => $driver]);

    expect(app(ShopifyTransport::class))->toBeInstanceOf(FakeShopifyTransport::class);
})->with(['fake', 'Fake', 'off', 'Live', 'real', '']);

it('uses the http shopify transport only for live', function () {
    config(['crm.shopify.driver' => 'live']);

    expect(app(ShopifyTransport::class))->toBeInstanceOf(HttpShopifyTransport::class);
});

it('uses the fake commerce provider for any commerce driver value other than live', function (string $driver) {
    config(['crm.drivers.commerce' => $driver]);

    expect(app(CommerceProvider::class))->toBeInstanceOf(FakeCommerceProvider::class);
})->with(['fake', 'Fake', 'off', 'Live', 'real', '']);

it('uses the shopify commerce provider only for live', function () {
    config(['crm.drivers.commerce' => 'live']);

    expect(app(CommerceProvider::class))->toBeInstanceOf(ShopifyCommerceProvider::class);
});
