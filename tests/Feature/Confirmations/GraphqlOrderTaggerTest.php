<?php

use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Models\Order;
use App\Shopify\Client\FakeShopifyTransport;
use App\Shopify\Client\ShopifyTransport;
use App\Shopify\Connection\ShopifyIntegration;

function taggerConnectShop(): void
{
    ShopifyIntegration::create(['shop_domain' => 'levoile-test.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
}

/** Binds a transport that answers every request through $handler and records the calls. */
function taggerTransport(Closure $handler): object
{
    $transport = new class($handler) implements ShopifyTransport
    {
        /** @var list<array{query: string, variables: array<mixed>}> */
        public array $calls = [];

        public function __construct(private readonly Closure $handler) {}

        public function post(string $url, array $headers, array $body): array
        {
            $variables = is_array($body['variables'] ?? null) ? $body['variables'] : [];
            $this->calls[] = ['query' => (string) $body['query'], 'variables' => $variables];

            return ($this->handler)((string) $body['query'], $variables);
        }
    };

    app()->instance(ShopifyTransport::class, $transport);
    app()->forgetScopedInstances();

    return $transport;
}

it('adds the tags with the tagsAdd mutation on the fake store', function () {
    taggerConnectShop();
    $order = Order::factory()->create(['shopify_order_id' => '5550001']);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-confirmed']))->toBeTrue()
        ->and(FakeShopifyTransport::tagsAdded())->toBe([['id' => 'gid://shopify/Order/5550001', 'tags' => ['wa-confirmed']]]);
});

it('sends the mutation with the order gid and tags', function () {
    taggerConnectShop();
    $transport = taggerTransport(fn () => ['status' => 200, 'json' => ['data' => ['tagsAdd' => ['node' => ['id' => 'x'], 'userErrors' => []]]], 'headers' => []]);
    $order = Order::factory()->create(['shopify_order_id' => '42']);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-cancel-request', 'wa-no-response']))->toBeTrue()
        ->and($transport->calls)->toHaveCount(1)
        ->and($transport->calls[0]['query'])->toContain('tagsAdd(')
        ->and($transport->calls[0]['variables'])->toBe(['id' => 'gid://shopify/Order/42', 'tags' => ['wa-cancel-request', 'wa-no-response']]);
});

it('returns false on userErrors without throwing', function () {
    taggerConnectShop();
    taggerTransport(fn () => ['status' => 200, 'json' => ['data' => ['tagsAdd' => ['node' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Order not found']]]]], 'headers' => []]);
    $order = Order::factory()->create(['shopify_order_id' => '42']);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-confirmed']))->toBeFalse();
});

it('returns false when Shopify is down or not connected', function () {
    $order = Order::factory()->create(['shopify_order_id' => '42']);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-confirmed']))->toBeFalse(); // no integration

    taggerConnectShop();
    taggerTransport(fn () => ['status' => 503, 'json' => [], 'headers' => []]);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-confirmed']))->toBeFalse();
});

it('returns false for an order that is not on Shopify yet, without calling it', function () {
    taggerConnectShop();
    $transport = taggerTransport(fn () => throw new RuntimeException('must not be called'));
    $order = Order::factory()->create(['shopify_order_id' => null]);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-confirmed']))->toBeFalse()
        ->and(app(ShopifyOrderTagger::class)->add(Order::factory()->create(['shopify_order_id' => '7']), []))->toBeTrue()
        ->and($transport->calls)->toBe([]);
});
