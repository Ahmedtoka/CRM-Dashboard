<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Events\ShopifyOrderCreated;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyTransport;
use App\Shopify\Commands\PollNewOrdersCommand;
use App\Shopify\Connection\IntegrationRepository;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/*
 * D4 (round 2026-10-17): every 5 minutes, orders created in Shopify since the last cursor are read
 * back; any order the CRM does not have is imported through the normal mapper and announced with
 * ShopifyOrderCreated, so an order whose orders/create webhook was lost still gets its WhatsApp
 * confirmation.
 */

function pollNode(string $id, array $overrides = []): array
{
    $page = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/orders_page_1.json')), true);
    $node = $page['data']['orders']['edges'][0]['node'];
    $node['id'] = "gid://shopify/Order/{$id}";
    $node['name'] = "#{$id}";
    $node['createdAt'] = now()->subMinutes(3)->utc()->format('Y-m-d\TH:i:s\Z');
    $node['updatedAt'] = now()->subMinutes(2)->utc()->format('Y-m-d\TH:i:s\Z');
    $node['lineItems']['edges'] = array_map(function (array $edge) use ($id) {
        $edge['node']['id'] = $edge['node']['id']."{$id}";

        return $edge;
    }, $node['lineItems']['edges']);
    $node['fulfillments'] = [];
    $node['refunds'] = [];
    $node['test'] = false;
    $node['sourceName'] = 'web';

    return array_replace($node, $overrides);
}

/**
 * Binds a transport answering the poll query with $pages (list of node lists, one per page)
 * and recording every request.
 *
 * @param  list<list<array<string, mixed>>>  $pages
 */
function pollTransport(array $pages): object
{
    $transport = new class($pages) implements ShopifyTransport
    {
        /** @var list<array{query: string, variables: array<mixed>}> */
        public array $calls = [];

        public function __construct(private array $pages) {}

        public function post(string $url, array $headers, array $body): array
        {
            $variables = is_array($body['variables'] ?? null) ? $body['variables'] : [];
            $this->calls[] = ['query' => (string) $body['query'], 'variables' => $variables];

            if (! str_contains((string) $body['query'], 'query pollNewOrders')) {
                return ['status' => 200, 'json' => ['data' => []], 'headers' => []];
            }

            $index = $variables['cursor'] === null ? 0 : (int) substr((string) $variables['cursor'], 1);
            $nodes = $this->pages[$index] ?? [];
            $hasNext = isset($this->pages[$index + 1]);

            return ['status' => 200, 'headers' => [], 'json' => ['data' => ['orders' => [
                'edges' => array_map(fn ($n) => ['node' => $n], $nodes),
                'pageInfo' => ['hasNextPage' => $hasNext, 'endCursor' => $hasNext ? 'p'.($index + 1) : null],
            ]]]];
        }
    };

    app()->instance(ShopifyTransport::class, $transport);
    app()->forgetScopedInstances();

    return $transport;
}

function pollConnect(array $attributes = []): ShopifyIntegration
{
    return ShopifyIntegration::create($attributes + [
        'shop_domain' => 'levoile-test.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected',
    ]);
}

beforeEach(function () {
    Event::fake([ShopifyOrderCreated::class]);
    Cache::forget(PollNewOrdersCommand::CURSOR_KEY);
});

it('imports an order the CRM does not have and announces it like orders/create', function () {
    pollConnect();
    pollTransport([[pollNode('9100001', ['test' => true, 'sourceName' => 'pos'])]]);

    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();

    $order = Order::where('shopify_order_id', '9100001')->firstOrFail();
    Event::assertDispatchedTimes(ShopifyOrderCreated::class, 1);
    Event::assertDispatched(ShopifyOrderCreated::class, fn ($e) => $e->orderId === $order->id && $e->test === true && $e->sourceName === 'pos');
});

it('gives a polled order the same confirmation row the webhook would', function () {
    Event::swap(Event::getFacadeRoot()->dispatcher); // the real listener runs
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    pollConnect();
    pollTransport([[pollNode('9100007')]]);

    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();
    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();

    $order = Order::where('shopify_order_id', '9100007')->firstOrFail();
    expect(OrderConfirmation::where('order_id', $order->id)->count())->toBe(1);
});

it('does not announce an order that already has a confirmation row', function () {
    pollConnect();
    pollTransport([[pollNode('9100002')]]);
    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();
    $order = Order::where('shopify_order_id', '9100002')->firstOrFail();
    OrderConfirmation::create(['order_id' => $order->id, 'status' => 'skipped', 'skip_reason' => 'no_phone']);

    Cache::forget(PollNewOrdersCommand::CURSOR_KEY);
    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();

    Event::assertDispatchedTimes(ShopifyOrderCreated::class, 1);
});

it('announces an order that exists without a confirmation only while confirmations are on', function () {
    pollConnect();
    pollTransport([[pollNode('9100003')]]);
    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();
    Event::assertDispatchedTimes(ShopifyOrderCreated::class, 1);

    // Order now exists, no confirmation row (confirmations off in tests by default): not re-announced.
    Cache::forget(PollNewOrdersCommand::CURSOR_KEY);
    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();
    Event::assertDispatchedTimes(ShopifyOrderCreated::class, 1);

    // Feature on and still no row (orders/create lost, orders/updated imported it): announced.
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    Cache::forget(PollNewOrdersCommand::CURSOR_KEY);
    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();
    Event::assertDispatchedTimes(ShopifyOrderCreated::class, 2);
});

it('asks Shopify for orders created since the cursor minus the overlap, never before the data floor', function () {
    pollConnect();
    $transport = pollTransport([[]]);
    Carbon::setTestNow('2026-10-17 12:00:00');
    Cache::forever(PollNewOrdersCommand::CURSOR_KEY, '2026-10-17T11:50:00+00:00');

    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();

    $query = collect($transport->calls)->firstWhere(fn ($c) => str_contains($c['query'], 'pollNewOrders'));
    expect($query['variables']['query'])->toBe("created_at:>='2026-10-17T11:40:00Z'")
        ->and($query['query'])->toContain('sortKey: CREATED_AT')
        ->and(Cache::get(PollNewOrdersCommand::CURSOR_KEY))->toBe('2026-10-17T12:00:00+00:00');

    // No cursor yet: looks back the confirmation max-age window, clamped to the data floor.
    Cache::forget(PollNewOrdersCommand::CURSOR_KEY);
    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();
    expect(collect($transport->calls)->last()['variables']['query'])->toBe("created_at:>='2026-10-17T10:00:00Z'");

    // ...and never before the floor's first instant (2026-10-17 00:00 Cairo = 2026-10-16 21:00 UTC).
    config(['crm.data_floor' => '2026-10-17']);
    Carbon::setTestNow('2026-10-16 22:30:00');
    Cache::forget(PollNewOrdersCommand::CURSOR_KEY);
    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();
    expect(collect($transport->calls)->last()['variables']['query'])->toBe("created_at:>='2026-10-16T21:00:00Z'");

    Carbon::setTestNow();
});

it('never imports an order created before the data floor', function () {
    config(['crm.data_floor' => '2026-10-01']);
    pollConnect();
    pollTransport([[pollNode('9100004', ['createdAt' => '2026-09-01T10:00:00Z'])]]);

    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();

    expect(Order::withLoadTest()->where('shopify_order_id', '9100004')->exists())->toBeFalse();
    Event::assertNotDispatched(ShopifyOrderCreated::class);
});

it('walks every page and keeps the cursor at the last order read when it stops at the page cap', function () {
    pollConnect();
    $pages = [];
    for ($p = 0; $p < PollNewOrdersCommand::MAX_PAGES + 1; $p++) {
        $pages[] = [pollNode((string) (9200000 + $p), ['createdAt' => now()->subMinutes(30 - $p)->utc()->format('Y-m-d\TH:i:s\Z')])];
    }
    pollTransport($pages);

    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();

    expect(Order::whereBetween('shopify_order_id', ['9200000', '9299999'])->count())->toBe(PollNewOrdersCommand::MAX_PAGES);
    $lastRead = $pages[PollNewOrdersCommand::MAX_PAGES - 1][0]['createdAt'];
    expect(Carbon::parse(Cache::get(PollNewOrdersCommand::CURSOR_KEY))->eq(Carbon::parse($lastRead)))->toBeTrue();
});

it('treats a load-test order with the same Shopify id as already known', function () {
    pollConnect();
    $existing = Order::factory()->create(['shopify_order_id' => '9100005', 'is_load_test' => true]);
    pollTransport([[pollNode('9100005')]]);

    $this->artisan('crm:shopify:poll-new-orders')->assertSuccessful();

    expect(Order::withLoadTest()->where('shopify_order_id', '9100005')->count())->toBe(1);
    Event::assertNotDispatched(ShopifyOrderCreated::class);
});

it('skips without calling Shopify when the store is not connected or is the demo shop', function (array $attributes) {
    pollConnect($attributes);
    $transport = pollTransport([[pollNode('9100006')]]);

    $this->artisan('crm:shopify:poll-new-orders')->expectsOutputToContain('skipped')->assertSuccessful();

    expect($transport->calls)->toBe([]);
})->with([
    'disconnected' => [['status' => 'disconnected']],
    'demo shop' => [['shop_domain' => ShopifyIntegration::DEMO_SHOP_DOMAIN]],
]);

it('keeps the cursor when Shopify fails so the next run retries the same window', function () {
    pollConnect();
    Cache::forever(PollNewOrdersCommand::CURSOR_KEY, '2026-10-17T11:50:00+00:00');
    $failing = new class implements ShopifyTransport
    {
        public function post(string $url, array $headers, array $body): array
        {
            return ['status' => 500, 'json' => ['errors' => 'boom'], 'headers' => []];
        }
    };
    app()->forgetScopedInstances();
    app()->instance(ShopifyClient::class, new ShopifyClient($failing, app(IntegrationRepository::class), fn () => null));

    $this->artisan('crm:shopify:poll-new-orders')->assertFailed();

    expect(Cache::get(PollNewOrdersCommand::CURSOR_KEY))->toBe('2026-10-17T11:50:00+00:00');
});

it('is scheduled every five minutes without overlapping, on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'crm:shopify:poll-new-orders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
