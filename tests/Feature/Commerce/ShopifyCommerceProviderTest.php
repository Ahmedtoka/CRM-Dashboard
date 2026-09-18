<?php

use App\Commerce\Data\OrderPayload;
use App\Commerce\ShopifyCommerceProvider;
use App\Enums\OrderType;
use App\Models\Order;
use App\Shopify\Client\{HttpShopifyTransport, ShopifyClient};
use App\Shopify\Connection\{IntegrationRepository, ShopifyIntegration};
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 'tok', 'api_secret' => 's', 'status' => 'connected']);
    $repo = app(IntegrationRepository::class);
    $this->provider = new ShopifyCommerceProvider($repo, new ShopifyClient(new HttpShopifyTransport(), $repo, fn () => null));
});

it('pins payment link lines to the crm price, uses purchasingEntity and reads the draft total', function () {
    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['draftOrderCreate' => [
        'draftOrder' => ['id' => 'gid://shopify/DraftOrder/55', 'name' => '#D55', 'invoiceUrl' => 'https://demo.myshopify.com/i/55', 'totalPriceSet' => ['shopMoney' => ['amount' => '960.0']]],
        'userErrors' => [],
    ]]])]);

    $order = Order::factory()->create(['type' => OrderType::PaymentLink, 'currency' => 'EGP']);

    $result = $this->provider->createPaymentLink(new OrderPayload(
        order: $order,
        lineItems: [['variant_shopify_id' => '111', 'title' => 'فستان', 'qty' => 2, 'price' => '500.00']],
        tags: ['social-crm', OrderPayload::tagFor($order->id)],
        note: 'Created by Mona',
        noteAttributes: ['crm_order_id' => $order->id],
        customerId: '777',
        shippingAddress: ['firstName' => 'Nour', 'provinceCode' => 'C', 'countryCode' => 'EG'],
        shippingLine: ['title' => 'شحن', 'price' => '60.00'],
        discount: ['type' => 'percent', 'value' => '10.00', 'amount' => '100.00', 'reason' => 'عميلة دائمة'],
    ));

    Http::assertSent(function (Request $r) use ($order) {
        $input = $r->data()['variables']['input'];

        return $input['lineItems'][0]['priceOverride'] === ['amount' => '500.00', 'currencyCode' => 'EGP']
            && $input['purchasingEntity'] === ['customerId' => 'gid://shopify/Customer/777']
            && ! array_key_exists('customerId', $input)
            && in_array(OrderPayload::tagFor($order->id), $input['tags'], true)
            && $input['appliedDiscount']['valueType'] === 'PERCENTAGE'
            && str_contains($r->data()['query'], 'totalPriceSet');
    });

    expect($result->draftOrderId)->toBe('55')
        ->and($result->invoiceUrl)->toBe('https://demo.myshopify.com/i/55')
        ->and($result->total)->toBe('960.00');
});

it('finds an already created order or draft by the install-unique crm order tag', function () {
    $cod = Order::factory()->create(['type' => OrderType::Cod]);
    $link = Order::factory()->create(['type' => OrderType::PaymentLink]);

    Http::fake(fn (Request $r) => Http::response(str_contains($r->data()['query'], 'draftOrders')
        ? ['data' => ['draftOrders' => ['nodes' => [[
            'id' => 'gid://shopify/DraftOrder/9', 'name' => '#D9', 'invoiceUrl' => 'https://demo.myshopify.com/i/9',
            'totalPriceSet' => ['shopMoney' => ['amount' => '10.5']],
            'createdAt' => now()->toIso8601String(),
            'customAttributes' => [['key' => 'crm_order_id', 'value' => (string) $link->id]],
        ]]]]]
        : ['data' => ['orders' => ['nodes' => []]]]));

    $found = $this->provider->findSubmittedOrder($link);

    expect($this->provider->findSubmittedOrder($cod))->toBeNull()
        ->and($found->draftOrderId)->toBe('9')
        ->and($found->invoiceUrl)->toBe('https://demo.myshopify.com/i/9')
        ->and($found->total)->toBe('10.50');

    $install = substr(sha1((string) config('app.key')), 0, 8);

    expect(OrderPayload::tagFor($link->id))->toBe("crm-{$install}-order-{$link->id}");
    Http::assertSent(fn (Request $r) => ($r->data()['variables']['query'] ?? null) === "tag:'crm-{$install}-order-{$link->id}'"
        && str_contains($r->data()['query'], 'first: 5'));
    Http::assertSent(fn (Request $r) => ($r->data()['variables']['query'] ?? null) === "tag:'crm-{$install}-order-{$cod->id}'" && str_contains($r->data()['query'], 'orders('));
});

it('ignores a tagged store order whose crm_order_id attribute belongs to another order', function () {
    $order = Order::factory()->create(['type' => OrderType::Cod]);

    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['orders' => ['nodes' => [[
        'id' => 'gid://shopify/Order/77', 'name' => '#77', 'createdAt' => now()->toIso8601String(),
        'customAttributes' => [['key' => 'crm_order_id', 'value' => (string) ($order->id + 1000)]],
    ]]]]])]);

    expect($this->provider->findSubmittedOrder($order))->toBeNull();
});

it('ignores a tagged store order created before the local order', function () {
    $order = Order::factory()->create(['type' => OrderType::Cod]);

    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['orders' => ['nodes' => [[
        'id' => 'gid://shopify/Order/78', 'name' => '#78', 'createdAt' => $order->created_at->copy()->subMinutes(11)->toIso8601String(),
        'customAttributes' => [['key' => 'crm_order_id', 'value' => (string) $order->id]],
    ]]]]])]);

    expect($this->provider->findSubmittedOrder($order))->toBeNull();
});

it('adopts the first verified node among the tagged store orders', function () {
    $order = Order::factory()->create(['type' => OrderType::Cod]);

    Http::fake(['demo.myshopify.com/*' => Http::response(['data' => ['orders' => ['nodes' => [
        ['id' => 'gid://shopify/Order/80', 'name' => '#80', 'createdAt' => now()->toIso8601String(), 'customAttributes' => [['key' => 'crm_order_id', 'value' => '999999']]],
        ['id' => 'gid://shopify/Order/81', 'name' => '#81', 'createdAt' => $order->created_at->copy()->subMinutes(5)->toIso8601String(), 'customAttributes' => [['key' => 'crm_order_id', 'value' => (string) $order->id]]],
    ]]]])]);

    $found = $this->provider->findSubmittedOrder($order);

    expect($found?->orderId)->toBe('81')->and($found?->orderNumber)->toBe('#81');
});
