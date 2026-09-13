<?php

use App\Commerce\ShippingQuote;
use App\Models\ShippingZone;
use App\Shopify\Connection\ShopifyIntegration;

it('quotes zone rates for the province and falls back to the default fee', function () {
    $z = ShippingZone::factory()->create(['name' => 'Cairo & Giza']);
    $z->regions()->create(['country_code' => 'EG', 'province_code' => 'C', 'province_name' => 'Cairo']);
    $z->rates()->create(['title' => 'عادي', 'price' => 60]);
    $z->rates()->create(['title' => 'مستعجل', 'price' => 100]);
    $z->rates()->create(['title' => 'مجاني فوق 2000', 'price' => 0, 'min_order_subtotal' => 2000]);
    expect(collect(app(ShippingQuote::class)->quote('C', '500.00'))->pluck('price')->all())->toBe(['60.00', '100.00'])
        ->and(app(ShippingQuote::class)->quote('ASN', '500.00')[0]->title)->toBe('شحن')
        ->and(app(ShippingQuote::class)->quote('ASN', '500.00')[0]->price)->toBe('60.00');
});

it('respects subtotal bounds, other countries and the stored default fee', function () {
    ShopifyIntegration::create(['shop_domain' => 'd.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected', 'settings' => ['default_shipping_fee' => 75]]);

    $z = ShippingZone::factory()->create();
    $z->regions()->create(['country_code' => 'EG', 'province_code' => 'GZ', 'province_name' => 'Giza']);
    $z->rates()->create(['title' => 'عادي', 'price' => 60, 'max_order_subtotal' => 1999.99]);
    $free = $z->rates()->create(['title' => 'مجاني', 'price' => 0, 'min_order_subtotal' => 2000]);

    $foreign = ShippingZone::factory()->create();
    $foreign->regions()->create(['country_code' => 'SA', 'province_code' => 'C', 'province_name' => 'X']);
    $foreign->rates()->create(['title' => 'دولي', 'price' => 500]);

    $big = app(ShippingQuote::class)->quote('GZ', '2500.00');

    expect($big)->toHaveCount(1)
        ->and($big[0]->rateId)->toBe($free->id)
        ->and($big[0]->price)->toBe('0.00')
        ->and(app(ShippingQuote::class)->quote('C', '100.00')[0]->price)->toBe('75.00')
        ->and(app(ShippingQuote::class)->quote(null, '100.00')[0]->rateId)->toBeNull();
});
