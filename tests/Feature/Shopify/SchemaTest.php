<?php

use App\Enums\OrderSource;
use App\Models\{Customer, Order, ShippingZone, Fulfillment};
use App\Shopify\Connection\ShopifyIntegration;

it('stores normalized phone and shopify relations', function () {
    $c = Customer::factory()->create(['phone' => '01001234567']);
    expect($c->fresh()->normalized_phone)->toBe('+201001234567');

    $order = Order::factory()->for($c)->create();
    expect($order->fresh()->source)->toBe(OrderSource::Chat);
    Fulfillment::factory()->for($order)->create(['tracking_number' => 'T1']);
    expect($order->fulfillments)->toHaveCount(1);

    $zone = ShippingZone::factory()->hasRegions(1, ['province_code' => 'C'])->hasRates(1, ['price' => 60])->create();
    expect($zone->rates->first()->price)->toBe('60.00');
});

it('encrypts integration secrets and provides setting defaults', function () {
    $i = ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'shpat_x', 'api_secret' => 's', 'status' => 'connected']);
    $raw = DB::table('shopify_integrations')->value('access_token');
    expect($raw)->not->toBe('shpat_x')->and($i->fresh()->access_token)->toBe('shpat_x')
        ->and($i->settingsWithDefaults())->toMatchArray(['default_shipping_fee' => 60.0, 'stuck_order_days' => 5, 'order_creation_enabled' => true]);
});
