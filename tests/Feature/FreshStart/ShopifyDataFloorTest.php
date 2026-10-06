<?php

use App\Models\Order;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunBulkImportStage;
use App\Shopify\Sync\BulkImporter;
use App\Shopify\Sync\IncrementalSync;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * F3: Shopify order import/sync never asks for, nor stores, an order created before crm.data_floor.
 */

beforeEach(function () {
    Event::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Africa/Cairo'));
    config(['crm.shopify.driver' => 'live', 'crm.data_floor' => '2026-10-01']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);

    // Order 5003 moves to 2026-10-02 (after the floor); 5001 and 5002 stay in June/July.
    $jsonl = str_replace('"createdAt":"2026-08-20T10:00:00Z"', '"createdAt":"2026-10-02T10:00:00Z"', file_get_contents(base_path('tests/Fixtures/shopify/bulk_orders.jsonl')));
    $created = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/bulk_operation_created.json')), true);
    $created['data']['bulkOperationRunQuery']['bulkOperation']['id'] = 'gid://shopify/BulkOperation/'.random_int(100000, 999999999);
    $poll = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/bulk_operation_completed.json')), true);
    foreach (['node', 'currentBulkOperation'] as $key) {
        if (isset($poll['data'][$key]['url'])) {
            $poll['data'][$key]['url'] = 'https://storage.shopifycloud.com/orders.jsonl';
        }
    }
    $page = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/orders_page_1.json')), true);
    $page['data']['orders']['pageInfo'] = ['hasNextPage' => false, 'endCursor' => null];

    Http::fake([
        'storage.shopifycloud.com/orders*' => fn () => Http::response($jsonl),
        'demo.myshopify.com/*' => function ($req) use ($created, $poll, $page) {
            $q = $req['query'] ?? '';
            if (str_contains($q, 'bulkOperationRunQuery')) {
                return Http::response($created);
            }
            if (str_contains($q, 'query sync')) {
                return Http::response($page);
            }

            return Http::response($poll);
        },
    ]);
});

function sfBulkQuery(): string
{
    return (string) collect(Http::recorded())->map(fn ($pair) => $pair[0]['query'] ?? '')->first(fn ($q) => str_contains($q, 'bulkOperationRunQuery'));
}

it('clamps a date-range orders import to the floor and stores only orders created after it', function () {
    Queue::fake();
    ShopifyIntegration::first()->update(['import_state' => ['stages' => [
        'shipping' => ['status' => 'completed'], 'products' => ['status' => 'completed'],
        'customers' => ['status' => 'completed'], 'orders' => ['status' => 'completed'],
    ]]]);

    app(BulkImporter::class)->importOrders('2026-09-01', '2026-10-31');
    Queue::assertPushed(RunBulkImportStage::class);
    app(BulkImporter::class)->runStage('orders');

    expect(sfBulkQuery())->toContain("created_at:>='2026-10-01T00:00:00+03:00'")
        ->and(sfBulkQuery())->not->toContain('2026-09-01')
        ->and(Order::pluck('shopify_order_id')->all())->toBe(['5003']);
});

it('clamps the initial import default window to the floor', function () {
    Queue::fake();

    app(BulkImporter::class)->start();

    expect(ShopifyIntegration::first()->import_state['orders_since'])->toBe('2026-10-01');
});

it('asks the paged order sync only for orders created since the floor and skips older ones', function () {
    $summary = app(IncrementalSync::class)->run('orders', now()->subMonths(2));

    Http::assertSent(fn ($r) => str_contains($r['query'] ?? '', 'query sync')
        && str_contains($r['variables']['query'] ?? '', "created_at:>='2026-09-30T21:00:00Z'"));
    expect(Order::count())->toBe(0)->and($summary->created)->toBe(0);
});

it('does not create an order from a webhook when it was created before the floor', function () {
    $o = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_order_store.json')), true);

    expect(app(OrderMapper::class)->upsert($o))->toBe(MapResult::Skipped)
        ->and(Order::count())->toBe(0);

    $o = array_replace($o, ['created_at' => '2026-10-03T14:20:00+03:00', 'updated_at' => '2026-10-03T14:22:05+03:00']);
    expect(app(OrderMapper::class)->upsert($o))->toBe(MapResult::Created)
        ->and(Order::count())->toBe(1);
});
