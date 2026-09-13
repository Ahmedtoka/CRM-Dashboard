<?php

use App\Shopify\Connection\ConnectionTester;
use Illuminate\Support\Facades\Http;

it('reports shop info and missing scopes', function () {
    config(['crm.shopify.driver' => 'live']);
    Http::fake(['good.myshopify.com/*' => Http::response(['data' => [
        'shop' => ['name' => 'Good Store', 'currencyCode' => 'EGP'],
        'currentAppInstallation' => ['accessScopes' => [['handle' => 'read_products'], ['handle' => 'read_orders'], ['handle' => 'write_orders']]],
    ]])]);
    $r = app(ConnectionTester::class)->test('good.myshopify.com', 'tok');
    expect($r->ok)->toBeTrue()->and($r->shopName)->toBe('Good Store')->and($r->currency)->toBe('EGP')
        ->and($r->missingScopes)->toContain('read_customers', 'write_draft_orders', 'read_shipping')->not->toContain('read_products');
});

it('rejects invalid domains without calling shopify', function () {
    Http::fake();
    $r = app(ConnectionTester::class)->test('evil.com', 'tok');
    expect($r->ok)->toBeFalse()->and($r->error)->not->toBeNull();
    Http::assertNothingSent();
});
