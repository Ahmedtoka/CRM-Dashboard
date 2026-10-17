<?php

use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Confirmations\Shopify\GraphqlOrderTagger;
use App\Models\Order;
use App\Shopify\Client\FakeShopifyTransport;
use App\Shopify\Client\ShopifyTransport;
use App\Shopify\Connection\ShopifyIntegration;

function taggerConnectShop(?array $scopes = null): ShopifyIntegration
{
    return ShopifyIntegration::create([
        'shop_domain' => 'levoile-test.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected',
        'granted_scopes' => $scopes,
    ]);
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

function okTags(): array
{
    return ['status' => 200, 'json' => ['data' => ['added' => ['userErrors' => []], 'removed' => ['userErrors' => []]]], 'headers' => []];
}

it('adds the tags with the tagsAdd mutation on the fake store', function () {
    taggerConnectShop();
    $order = Order::factory()->create(['shopify_order_id' => '5550001']);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-confirmed']))->toBeTrue()
        ->and(FakeShopifyTransport::tagsAdded())->toBe([['id' => 'gid://shopify/Order/5550001', 'tags' => ['wa-confirmed']]])
        ->and(FakeShopifyTransport::tagsRemoved())->toBe([]);
});

it('adds and removes tags in one request', function () {
    taggerConnectShop(['read_orders', 'write_orders']);
    $transport = taggerTransport(fn () => okTags());
    $order = Order::factory()->create(['shopify_order_id' => '42']);

    expect(app(GraphqlOrderTagger::class)->replace($order, ['wa-confirmed'], ['wa-no-response', 'wa-confirmed']))->toBeTrue()
        ->and($transport->calls)->toHaveCount(1)
        ->and($transport->calls[0]['query'])->toContain('tagsAdd(')->toContain('tagsRemove(')
        ->and($transport->calls[0]['variables'])->toBe(['id' => 'gid://shopify/Order/42', 'add' => ['wa-confirmed'], 'remove' => ['wa-no-response']]);
});

it('returns false on userErrors without throwing', function () {
    taggerConnectShop();
    taggerTransport(fn () => ['status' => 200, 'json' => ['data' => ['added' => ['userErrors' => [['field' => ['id'], 'message' => 'Order not found']]]]], 'headers' => []]);
    $order = Order::factory()->create(['shopify_order_id' => '42']);
    $tagger = app(GraphqlOrderTagger::class);

    expect($tagger->add($order, ['wa-confirmed']))->toBeFalse()
        ->and($tagger->lastFailure())->toContain('Order not found');
});

it('returns false when Shopify is down or not connected', function () {
    $order = Order::factory()->create(['shopify_order_id' => '42']);
    $tagger = app(GraphqlOrderTagger::class);

    expect($tagger->add($order, ['wa-confirmed']))->toBeFalse() // no integration
        ->and($tagger->lastFailure())->toBe('Shopify is not connected');

    taggerConnectShop();
    taggerTransport(fn () => ['status' => 503, 'json' => [], 'headers' => []]);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-confirmed']))->toBeFalse();
});

it('does not call Shopify when the app lacks write_orders', function () {
    taggerConnectShop(['read_orders', 'read_customers']);
    $transport = taggerTransport(fn () => throw new RuntimeException('must not be called'));
    $tagger = app(GraphqlOrderTagger::class);

    expect($tagger->add(Order::factory()->create(['shopify_order_id' => '42']), ['wa-confirmed']))->toBeFalse()
        ->and($tagger->lastFailure())->toBe(GraphqlOrderTagger::MISSING_SCOPE)
        ->and($transport->calls)->toBe([]);
});

it('never flags the integration as broken when Shopify refuses the tag', function () {
    $integration = taggerConnectShop(); // scopes not recorded: it tries
    taggerTransport(fn () => ['status' => 200, 'json' => ['errors' => [['message' => 'Access denied for tagsAdd field.', 'extensions' => ['code' => 'ACCESS_DENIED']]]], 'headers' => []]);
    $tagger = app(GraphqlOrderTagger::class);

    expect($tagger->add(Order::factory()->create(['shopify_order_id' => '42']), ['wa-confirmed']))->toBeFalse()
        ->and($tagger->lastFailure())->toContain('write_orders')
        ->and($integration->fresh()->status)->toBe('connected');

    taggerTransport(fn () => ['status' => 403, 'json' => [], 'headers' => []]);

    expect($tagger->add(Order::factory()->create(['shopify_order_id' => '43']), ['wa-confirmed']))->toBeFalse()
        ->and($integration->fresh()->status)->toBe('connected');
});

it('returns false for an order that is not on Shopify yet, without calling it', function () {
    taggerConnectShop();
    $transport = taggerTransport(fn () => throw new RuntimeException('must not be called'));
    $order = Order::factory()->create(['shopify_order_id' => null]);

    expect(app(ShopifyOrderTagger::class)->add($order, ['wa-confirmed']))->toBeFalse()
        ->and(app(ShopifyOrderTagger::class)->add(Order::factory()->create(['shopify_order_id' => '7']), []))->toBeTrue()
        ->and($transport->calls)->toBe([]);
});
