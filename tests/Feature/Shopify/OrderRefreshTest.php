<?php

use App\Enums\OrderSource;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Events\OrderUpdated;
use App\Models\Order;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Client\ShopifyTransport;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RefreshShopifyOrders;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use App\Shopify\Sync\OrderRefresher;
use App\Shopify\Sync\SyncQueries;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Event::fake());

function refreshFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/shopify/{$name}.json")), true);
}

/** A GraphQL order node (the paged-sync fixture) for Shopify order $id. */
function refreshNode(string $id, array $overrides = []): array
{
    $page = refreshFixture('orders_page_1');
    $node = $page['data']['orders']['edges'][0]['node'];
    $node['id'] = "gid://shopify/Order/{$id}";
    $node['name'] = "#{$id}";
    $node['lineItems']['edges'] = array_map(function (array $edge) use ($id) {
        $edge['node']['id'] = $edge['node']['id']."{$id}";

        return $edge;
    }, $node['lineItems']['edges']);
    $node['fulfillments'] = [];
    $node['refunds'] = [];

    return array_replace($node, $overrides);
}

function refreshConnectShop(string $domain = 'levoile-test.myshopify.com'): void
{
    ShopifyIntegration::create(['shop_domain' => $domain, 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
}

/**
 * Binds a fake ShopifyTransport (so a real ShopifyClient is built around it) that
 * answers through $handler(query, variables) and records every request.
 */
function refreshTransport(Closure $handler): object
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

/** Answers `nodes(ids:)` with refreshNode() for every id, through $node(id) when given (null = deleted). */
function refreshNodesTransport(?Closure $node = null): object
{
    return refreshTransport(function (string $query, array $variables) use ($node) {
        if (! str_contains($query, 'nodes(ids:')) {
            return ['status' => 200, 'json' => ['data' => []], 'headers' => []];
        }

        $nodes = array_map(function (string $gid) use ($node) {
            $id = substr($gid, strrpos($gid, '/') + 1);

            return $node !== null ? $node($id) : refreshNode($id, ['updatedAt' => '2030-01-01T00:00:00Z']);
        }, $variables['ids'] ?? []);

        return ['status' => 200, 'json' => ['data' => ['nodes' => $nodes]], 'headers' => []];
    });
}

function refreshStoreOrder(array $attributes = []): Order
{
    return Order::factory()->create(array_merge([
        'source' => OrderSource::Store,
        'platform' => Platform::Instagram,
        'shopify_order_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        'shopify_updated_at' => '2026-01-01 00:00:00',
    ], $attributes));
}

// 1. Every Shopify read stamps last_synced_at, including a stale (skipped) one.
it('stamps last_synced_at on every mapper read, also when the payload is skipped as stale', function () {
    $this->freezeTime();
    $payload = refreshFixture('webhook_order_store');

    expect(app(OrderMapper::class)->upsert($payload))->toBe(MapResult::Created);
    $order = Order::where('shopify_order_id', (string) $payload['id'])->firstOrFail();
    expect($order->last_synced_at?->toIso8601String())->toBe(now()->toIso8601String());
    $updatedAt = $order->updated_at->toIso8601String();

    $this->travel(5)->minutes();

    expect(app(OrderMapper::class)->upsert($payload))->toBe(MapResult::Skipped);
    $order->refresh();
    expect($order->last_synced_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($order->updated_at->toIso8601String())->toBe($updatedAt);
});

// 2. R9: a chat order takes the Shopify note, and only the note.
it('gives a chat order the note from Shopify and leaves its money untouched', function () {
    $payload = refreshFixture('webhook_order_chat');
    $order = Order::factory()->create([
        'source' => OrderSource::Chat,
        'shopify_order_id' => (string) $payload['id'],
        'shopify_updated_at' => '2026-09-01 00:00:00',
        'note' => 'قديمة',
        'subtotal' => 111, 'shipping_fee' => 22, 'discount' => 0, 'total' => 133,
    ]);

    $result = app(OrderMapper::class)->upsert(array_replace($payload, ['note' => 'جديدة من شوبيفاي', 'updated_at' => '2030-01-01T00:00:00+02:00']));

    $order->refresh();
    expect($result)->toBe(MapResult::Updated)
        ->and($order->note)->toBe('جديدة من شوبيفاي')
        ->and((float) $order->total)->toBe(133.0)
        ->and((float) $order->subtotal)->toBe(111.0)
        ->and($order->last_synced_at)->not->toBeNull();
});

it('keeps a chat order note when the payload does not carry one', function () {
    $payload = refreshFixture('webhook_order_chat');
    unset($payload['note']);
    $order = Order::factory()->create(['source' => OrderSource::Chat, 'shopify_order_id' => (string) $payload['id'],
        'shopify_updated_at' => '2026-09-01 00:00:00', 'note' => 'قديمة']);

    app(OrderMapper::class)->upsert(array_replace($payload, ['updated_at' => '2030-01-01T00:00:00+02:00']));

    expect($order->fresh()->note)->toBe('قديمة');
});

// 3. OrderRefresher.
it('keeps the refresh document within the query cost cap for a full batch', function () {
    expect(SyncQueries::estimatedWorstCaseCost(SyncQueries::ordersByIds()))->toBeLessThanOrEqual(SyncQueries::MAX_QUERY_COST)
        ->and(SyncQueries::cappedDocuments())->toHaveKey('orders_by_ids')
        // One more order per batch would no longer fit: the batch is as large as the cap allows.
        ->and(SyncQueries::estimatedWorstCaseCost(SyncQueries::ordersByIds(), OrderRefresher::BATCH + 1))->toBeGreaterThan(SyncQueries::MAX_QUERY_COST);
});

it('refreshes orders in batches through nodes(ids:), maps every node and logs a refresh run', function () {
    refreshConnectShop();
    $orders = collect(range(1, 30))->map(fn ($i) => refreshStoreOrder(['shopify_order_id' => (string) (7000 + $i)]));
    $transport = refreshNodesTransport();

    $result = app(OrderRefresher::class)->refresh($orders->pluck('id')->all());

    $batches = (int) ceil(30 / OrderRefresher::BATCH);
    $sizes = array_map(fn ($c) => count($c['variables']['ids']), $transport->calls);
    expect($result)->toBe(['refreshed' => 30, 'skipped' => 0, 'failed' => 0])
        ->and($transport->calls)->toHaveCount($batches)
        ->and($sizes[0])->toBe(OrderRefresher::BATCH)
        ->and(array_sum($sizes))->toBe(30)
        ->and($transport->calls[0]['variables']['ids'][0])->toBe('gid://shopify/Order/7001')
        ->and(Order::whereKey($orders->pluck('id'))->where('financial_status', 'paid')->count())->toBe(30)
        ->and(Order::whereKey($orders->pluck('id'))->whereNull('last_synced_at')->count())->toBe(0);

    $run = ShopifySyncRun::latest('id')->first();
    expect($run->type)->toBe('refresh')->and($run->resource)->toBe('orders')->and($run->status)->toBe('completed')
        ->and($run->processed)->toBe(30);
});

it('counts a node deleted in Shopify as skipped and leaves its order untouched', function () {
    refreshConnectShop();
    $kept = refreshStoreOrder(['shopify_order_id' => '8001', 'financial_status' => 'pending']);
    $gone = refreshStoreOrder(['shopify_order_id' => '8002', 'financial_status' => 'pending']);
    refreshNodesTransport(fn (string $id) => $id === '8002' ? null : refreshNode($id, ['updatedAt' => '2030-01-01T00:00:00Z']));

    $result = app(OrderRefresher::class)->refresh([$kept->id, $gone->id]);

    expect($result)->toBe(['refreshed' => 1, 'skipped' => 1, 'failed' => 0])
        ->and($kept->fresh()->financial_status)->toBe('paid')
        ->and($gone->fresh()->financial_status)->toBe('pending')
        ->and($gone->fresh()->last_synced_at)->toBeNull();
});

it('counts a failed batch as failed and goes on with the next one', function () {
    refreshConnectShop();
    $orders = collect(range(1, OrderRefresher::BATCH + 2))->map(fn ($i) => refreshStoreOrder(['shopify_order_id' => (string) (9000 + $i)]));
    $calls = 0;
    refreshTransport(function (string $query, array $variables) use (&$calls) {
        if (++$calls === 1) {
            return ['status' => 200, 'json' => ['errors' => [['message' => 'Internal error']]], 'headers' => []];
        }

        $nodes = array_map(fn ($gid) => refreshNode(substr($gid, strrpos($gid, '/') + 1), ['updatedAt' => '2030-01-01T00:00:00Z']), $variables['ids']);

        return ['status' => 200, 'json' => ['data' => ['nodes' => $nodes]], 'headers' => []];
    });

    $result = app(OrderRefresher::class)->refresh($orders->pluck('id')->all());

    expect($result)->toBe(['refreshed' => 2, 'skipped' => 0, 'failed' => OrderRefresher::BATCH]);
    $run = ShopifySyncRun::latest('id')->first();
    expect($run->status)->toBe('completed')->and($run->failed)->toBe(OrderRefresher::BATCH)->and($run->errors)->not->toBeEmpty();
});

it('broadcasts an unchanged (stale) order once so the screens see the new sync time', function () {
    refreshConnectShop();
    $order = refreshStoreOrder(['shopify_order_id' => '8101', 'shopify_updated_at' => '2030-01-01 00:00:00']);
    refreshNodesTransport(fn (string $id) => refreshNode($id, ['updatedAt' => '2030-01-01T00:00:00Z']));

    $result = app(OrderRefresher::class)->refresh([$order->id]);

    expect($result['refreshed'])->toBe(1);
    Event::assertDispatchedTimes(OrderUpdated::class, 1);
});

it('never queries Shopify for orders that are not on it', function () {
    refreshConnectShop();
    $draft = Order::factory()->create(['source' => OrderSource::Chat, 'shopify_order_id' => null]);
    $transport = refreshNodesTransport();

    expect(app(OrderRefresher::class)->refresh([$draft->id]))->toBe(['refreshed' => 0, 'skipped' => 1, 'failed' => 0])
        ->and($transport->calls)->toBe([]);
});

// 4. The scheduled command.
it('queues the oldest-synced open Shopify orders, never final or unsubmitted ones', function () {
    refreshConnectShop();
    Queue::fake();
    $this->freezeTime();
    $never = refreshStoreOrder(['last_synced_at' => null]);
    $old = refreshStoreOrder(['last_synced_at' => now()->subMinutes(40)]);
    $older = refreshStoreOrder(['last_synced_at' => now()->subMinutes(20)]);
    refreshStoreOrder(['last_synced_at' => now()->subMinutes(5)]); // synced recently
    refreshStoreOrder(['last_synced_at' => null, 'cancelled_at' => now()->subDay()]);
    refreshStoreOrder(['last_synced_at' => null, 'financial_status' => 'refunded']);
    refreshStoreOrder(['last_synced_at' => null, 'financial_status' => 'voided']);
    refreshStoreOrder(['last_synced_at' => null, 'delivered_at' => now()->subDay()]);
    refreshStoreOrder(['last_synced_at' => null, 'fulfillment_status' => 'fulfilled', 'shipment_status' => 'delivered']);
    Order::factory()->create(['source' => OrderSource::Chat, 'shopify_order_id' => null, 'last_synced_at' => null]);

    $this->artisan('shopify:refresh-orders', ['--limit' => 2, '--older-than' => 10])
        ->expectsOutputToContain('queued=2')
        ->assertSuccessful();

    Queue::assertPushed(RefreshShopifyOrders::class, 1);
    Queue::assertPushed(RefreshShopifyOrders::class, fn (RefreshShopifyOrders $job) => $job->orderIds === [$never->id, $old->id]
        && $job->queue === 'commercelong' && $job->tries === 2 && $job->backoff === 60);
    expect($older->id)->not->toBeIn([$never->id, $old->id]);
});

it('lists open orders with a partial fulfilment or no shipment status as open for sync', function () {
    $partial = refreshStoreOrder(['fulfillment_status' => 'fulfilled', 'shipment_status' => 'in_transit']);
    $plain = refreshStoreOrder();
    refreshStoreOrder(['fulfillment_status' => 'fulfilled', 'shipment_status' => 'delivered']);

    expect(Order::openForSync()->pluck('id')->sort()->values()->all())->toBe([$partial->id, $plain->id]);
});

it('chunks the queued ids into jobs of 25 orders', function () {
    refreshConnectShop();
    Queue::fake();
    collect(range(1, 26))->each(fn () => refreshStoreOrder());

    $this->artisan('shopify:refresh-orders')->expectsOutputToContain('queued=26')->assertSuccessful();

    Queue::assertPushed(RefreshShopifyOrders::class, 2);
    Queue::assertPushed(RefreshShopifyOrders::class, fn (RefreshShopifyOrders $job) => count($job->orderIds) === 25);
});

it('skips the scheduled refresh when Shopify is not connected', function () {
    Queue::fake();
    refreshStoreOrder();

    $this->artisan('shopify:refresh-orders')->expectsOutputToContain('skipped: not connected')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('schedules the open-order refresh every ten minutes on one server without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'shopify:refresh-orders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('runs the queued job through the refresher', function () {
    refreshConnectShop();
    $order = refreshStoreOrder(['shopify_order_id' => '8201', 'financial_status' => 'pending']);
    refreshNodesTransport();

    app()->call([new RefreshShopifyOrders([$order->id]), 'handle']);

    expect($order->fresh()->financial_status)->toBe('paid');
});

// 5. Endpoints.
it('refreshes one order from Shopify and returns it fresh', function () {
    refreshConnectShop();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $order = refreshStoreOrder(['shopify_order_id' => '8301', 'financial_status' => 'pending', 'last_synced_at' => null]);
    refreshNodesTransport(fn (string $id) => refreshNode($id, ['updatedAt' => '2030-01-01T00:00:00Z', 'note' => 'سيبيه عند البواب']));

    $this->actingAs($admin)->postJson("/orders/{$order->id}/refresh")
        ->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonPath('data.financial_status', 'paid')
        ->assertJsonPath('data.note', 'سيبيه عند البواب')
        ->assertJsonPath('data.on_shopify', true)
        ->assertJsonPath('data.shopify_updated_at', '2030-01-01T00:00:00+00:00')
        ->assertJsonPath('data.last_synced_at', fn ($v) => is_string($v) && $v !== '');
});

it('answers 409 for an order that is not on Shopify', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $order = Order::factory()->create(['source' => OrderSource::Chat, 'shopify_order_id' => null]);

    $this->actingAs($admin)->postJson("/orders/{$order->id}/refresh")
        ->assertStatus(409)
        ->assertJsonPath('message', __('errors.orders.not_on_shopify'));
});

it('answers 503 when Shopify fails', function () {
    refreshConnectShop();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $order = refreshStoreOrder();
    refreshTransport(fn () => ['status' => 200, 'json' => ['errors' => [['message' => 'Internal error']]], 'headers' => []]);

    $this->actingAs($admin)->postJson("/orders/{$order->id}/refresh")
        ->assertStatus(503)
        ->assertJsonPath('message', __('errors.orders.refresh_failed'));
});

it('answers 503 when Shopify is not connected', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $order = refreshStoreOrder();

    $this->actingAs($admin)->postJson("/orders/{$order->id}/refresh")->assertStatus(503);
});

it('forbids the refresh to a moderator without the order platform, like show', function () {
    refreshConnectShop();
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $order = refreshStoreOrder(['platform' => Platform::Instagram]);
    $transport = refreshNodesTransport();

    $this->actingAs($mod)->postJson("/orders/{$order->id}/refresh")->assertForbidden();
    expect($transport->calls)->toBe([]);

    $mod->userPlatforms()->create(['platform' => Platform::Instagram]);
    $this->actingAs($mod->fresh())->postJson("/orders/{$order->id}/refresh")->assertOk();
});

it('queues only stale, open Shopify orders for a background refresh, once per five minutes', function () {
    Queue::fake();
    $this->freezeTime();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $fresh = refreshStoreOrder(['last_synced_at' => now()->subMinutes(5)]);
    $final = refreshStoreOrder(['last_synced_at' => now()->subHours(2), 'cancelled_at' => now()->subDay()]);
    $stale = refreshStoreOrder(['last_synced_at' => now()->subMinutes(45)]);
    $ids = [$fresh->id, $final->id, $stale->id];

    $this->actingAs($admin)->postJson('/orders/refresh-stale', ['ids' => $ids])->assertStatus(202)->assertJson(['queued' => 1]);
    Queue::assertPushed(RefreshShopifyOrders::class, fn (RefreshShopifyOrders $job) => $job->orderIds === [$stale->id]);

    $this->actingAs($admin)->postJson('/orders/refresh-stale', ['ids' => $ids])->assertStatus(202)->assertJson(['queued' => 0]);
    Queue::assertPushed(RefreshShopifyOrders::class, 1);

    $this->travel(6)->minutes();
    $this->actingAs($admin)->postJson('/orders/refresh-stale', ['ids' => $ids])->assertJson(['queued' => 1]);
});

it('validates the stale refresh ids and only queues orders the user may see', function () {
    Queue::fake();
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $hidden = refreshStoreOrder(['platform' => Platform::Instagram, 'last_synced_at' => null]);

    $this->actingAs($mod)->postJson('/orders/refresh-stale', ['ids' => range(1, 51)])->assertUnprocessable();
    $this->actingAs($mod)->postJson('/orders/refresh-stale', ['ids' => ['x']])->assertUnprocessable();
    $this->actingAs($mod)->postJson('/orders/refresh-stale', ['ids' => [$hidden->id]])->assertJson(['queued' => 0]);
    Queue::assertNothingPushed();
});

// Supporting pieces.
it('gives the web refresh a client that waits at most the given seconds and never resends', function () {
    refreshConnectShop();
    config(['crm.shopify.driver' => 'live']);
    app()->forgetScopedInstances();
    Http::fake(['levoile-test.myshopify.com/*' => Http::response([], 502)]);

    expect(fn () => app(ShopifyClient::class)->withTimeout(10)->query('query { shop { name } }'))
        ->toThrow(ShopifyException::class);
    Http::assertSentCount(1);
});

it('answers a refresh on the fake driver with the stored order, so only the sync time moves', function () {
    $this->freezeTime();
    refreshConnectShop(ShopifyIntegration::DEMO_SHOP_DOMAIN);
    config(['crm.shopify.driver' => 'fake']);
    app()->forgetInstance(ShopifyTransport::class);
    app()->forgetScopedInstances();
    $store = refreshStoreOrder(['shopify_order_id' => '8401', 'financial_status' => 'paid', 'note' => 'ملاحظة', 'shipping_name' => 'نور']);
    $chat = Order::factory()->create(['source' => OrderSource::Chat, 'shopify_order_id' => '8402', 'financial_status' => 'pending',
        'fulfillment_status' => 'partial', 'note' => 'من المحادثة', 'shopify_updated_at' => null]);
    $neverSynced = refreshStoreOrder(['shopify_order_id' => '8403', 'shopify_updated_at' => null, 'shipping_name' => 'سارة']);
    $before = [$store->fresh()->toArray(), $chat->fresh()->toArray()];

    $result = app(OrderRefresher::class)->refresh([$store->id, $chat->id, $neverSynced->id]);

    expect($result)->toBe(['refreshed' => 2, 'skipped' => 1, 'failed' => 0])
        ->and($store->fresh()->last_synced_at)->not->toBeNull()
        ->and($chat->fresh()->last_synced_at)->not->toBeNull()
        ->and($neverSynced->fresh()->last_synced_at)->toBeNull()
        ->and($neverSynced->fresh()->shipping_name)->toBe('سارة');
    foreach ([$store, $chat] as $i => $order) {
        $after = $order->fresh()->toArray();
        foreach (['financial_status', 'fulfillment_status', 'note', 'total', 'shipping_name', 'customer_id', 'status'] as $field) {
            expect($after[$field])->toBe($before[$i][$field], "{$field} of order {$order->id}");
        }
    }
});

it('carries the sync fields on the OrderUpdated broadcast', function () {
    $order = refreshStoreOrder(['last_synced_at' => '2026-10-01 10:00:00', 'note' => 'ن']);

    expect((new OrderUpdated($order->fresh()))->broadcastWith())
        ->toHaveKeys(['financial_status', 'fulfillment_status', 'shipment_status', 'note', 'shopify_updated_at', 'last_synced_at', 'updated_at'])
        ->and((new OrderUpdated($order->fresh()))->broadcastWith()['last_synced_at'])->toBe($order->fresh()->last_synced_at->toIso8601String());
});

it('keeps order refresh runs out of the Shopify settings sync log and last-sync times', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    ShopifyIntegration::create(['shop_domain' => 'levoile-test.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected', 'import_state' => ['stages' => []]]);
    ShopifySyncRun::create(['type' => 'manual', 'resource' => 'orders', 'status' => 'completed', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
    ShopifySyncRun::create(['type' => 'refresh', 'resource' => 'orders', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);

    $this->actingAs($admin)->getJson('/settings/shopify/status')
        ->assertOk()
        ->assertJsonCount(1, 'runs')
        ->assertJsonPath('runs.0.type', 'manual');
});
