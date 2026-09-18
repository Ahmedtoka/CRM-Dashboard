<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingZone;
use App\Models\ShopifySyncRun;
use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunBulkImportStage;
use App\Shopify\Sync\BulkImporter;
use App\Shopify\Sync\ImportAlreadyRunningException;
use App\Shopify\Sync\JsonlReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The shipping stage reads `deliveryProfiles`; shipping_zones.json holds the bare
 * `locationGroupZones` nodes, so wrap them in the GraphQL response envelope.
 */
function bulkShippingProfilesResponse(?array $zones = null, bool $hasNextPage = false): array
{
    $zones ??= json_decode(file_get_contents(base_path('tests/Fixtures/shopify/shipping_zones.json')), true);

    return ['data' => ['deliveryProfiles' => [
        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        'nodes' => [[
            'id' => 'gid://shopify/DeliveryProfile/1',
            'profileLocationGroups' => [[
                'locationGroup' => ['id' => 'gid://shopify/DeliveryLocationGroup/1'],
                'locationGroupZones' => ['pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $hasNextPage ? 'zones-1' : null], 'nodes' => $zones],
            ]],
        ]],
    ]]];
}

function importState(array $stages): array
{
    return ['stages' => $stages];
}

beforeEach(function () {
    Event::fake();
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
    $fx = fn ($n) => file_get_contents(base_path("tests/Fixtures/shopify/{$n}"));

    // Http fakes match in registration order (first wins), so per-test overrides go
    // through these properties instead of a second Http::fake.
    $this->productsJsonl = $fx('bulk_products.jsonl');
    $this->pollFixture = 'bulk_operation_completed.json';
    $this->shippingResponse = null;
    $this->shippingFollowUp = null;
    $this->bulkRunResponse = null;
    // A per-test operation id keeps the downloaded storage/app/shopify/{stage}-{id}.jsonl
    // unique, so test processes running concurrently in one checkout never share a file.
    $this->operationId = 'gid://shopify/BulkOperation/'.random_int(100000, 999999999);
    $created = json_decode($fx('bulk_operation_created.json'), true);
    $created['data']['bulkOperationRunQuery']['bulkOperation']['id'] = $this->operationId;
    $stage = 'products';

    Http::fake([
        'storage.shopifycloud.com/products*' => function ($req) {
            if (preg_match('/bytes=(\d+)-/', $req->header('Range')[0] ?? '', $m) === 1) {
                return Http::response(substr($this->productsJsonl, (int) $m[1]), 206);
            }

            return Http::response($this->productsJsonl);
        },
        'storage.shopifycloud.com/customers*' => fn () => Http::response($fx('bulk_customers.jsonl')),
        'storage.shopifycloud.com/orders*' => fn () => Http::response($fx('bulk_orders.jsonl')),
        'demo.myshopify.com/*' => function ($req) use ($fx, $created, &$stage) {
            $q = $req['query'] ?? '';
            if (str_contains($q, 'deliveryProfileZones')) {
                return Http::response($this->shippingFollowUp);
            }
            if (str_contains($q, 'deliveryProfiles')) {
                return Http::response($this->shippingResponse ?? bulkShippingProfilesResponse());
            }
            if (str_contains($q, 'bulkOperationRunQuery')) {
                $stage = str_contains($q, 'orders') ? 'orders' : (str_contains($q, 'customers') ? 'customers' : 'products');

                return Http::response($this->bulkRunResponse ?? $created);
            }
            $poll = is_array($this->pollFixture) ? $this->pollFixture : json_decode($fx($this->pollFixture), true);
            foreach (['node', 'currentBulkOperation'] as $key) {
                if (isset($poll['data'][$key]['url'])) {
                    $poll['data'][$key]['url'] = "https://storage.shopifycloud.com/{$stage}.jsonl";
                }
            }

            return Http::response($poll);
        },
    ]);
});

it('imports every stage in order and records a completed run', function () {
    app(BulkImporter::class)->start(); // sync queue runs all stages
    $state = ShopifyIntegration::first()->import_state;
    expect(collect($state['stages'])->pluck('status')->unique()->all())->toBe(['completed'])
        ->and(ShippingZone::count())->toBeGreaterThan(0)
        ->and(Product::count())->toBeGreaterThan(0)->and(Customer::whereNotNull('shopify_customer_id')->count())->toBeGreaterThan(0)
        ->and(Order::where('source', 'store')->count())->toBeGreaterThan(0)
        ->and(ShopifySyncRun::where('type', 'initial')->where('status', 'completed')->exists())->toBeTrue();
});

it('records a bad row and continues the stage', function () {
    $valid = file_get_contents(base_path('tests/Fixtures/shopify/bulk_products.jsonl'));
    $validRows = count(array_filter(explode("\n", $valid), fn ($l) => str_contains($l, 'gid://shopify/Product/')));
    $this->productsJsonl = "{\"id\":\"gid://shopify/Product/1\",\"title\":null,\"updatedAt\":null}\n".$valid;
    app(BulkImporter::class)->runStage('products');
    $run = ShopifySyncRun::latest('id')->first();
    expect($run->failed)->toBe(1)
        ->and(Product::count())->toBe($validRows)
        ->and(ShopifyIntegration::first()->import_state['stages']['products']['status'])->toBe('completed');
});

it('resumes from the first incomplete stage', function () {
    Queue::fake();
    $i = ShopifyIntegration::first();
    $i->update(['import_state' => ['stages' => ['shipping' => ['status' => 'completed'], 'products' => ['status' => 'completed'], 'customers' => ['status' => 'failed'], 'orders' => ['status' => 'pending']]]]);
    app(BulkImporter::class)->resume();
    Queue::assertPushed(\App\Shopify\Jobs\RunBulkImportStage::class, fn ($job) => $job->stage === 'customers');
});

it('maps fulfillments, refunds and line items and recomputes customer flags once the chunk commits', function () {
    app(BulkImporter::class)->start();

    $order = Order::where('shopify_order_id', '5002')->firstOrFail();
    $mona = Customer::where('shopify_customer_id', '3001')->firstOrFail();

    expect(Order::where('shopify_order_id', '5003')->firstOrFail()->items()->count())->toBe(2)
        ->and($order->refunds()->count())->toBe(1)
        ->and(Order::where('shopify_order_id', '5001')->firstOrFail()->fulfillments()->value('shipment_status'))->toBe('delivered')
        ->and($mona->addresses()->count())->toBe(2)
        ->and((bool) $mona->is_repeat)->toBeTrue()
        ->and((bool) $mona->has_return)->toBeTrue()
        ->and(ShopifyIntegration::first()->import_state['orders_since'])->toBe(now()->subMonths(12)->toDateString());
});

it('imports variant child lines end to end', function () {
    $this->productsJsonl = file_get_contents(base_path('tests/Fixtures/shopify/bulk_products_with_variants.jsonl'));

    expect(app(BulkImporter::class)->runStage('products'))->toBe(BulkImporter::OUTCOME_COMPLETED)
        ->and(Product::count())->toBe(5)
        ->and(ProductVariant::count())->toBe(6)
        ->and(ProductVariant::where('product_id', Product::where('shopify_id', '2103')->value('id'))->pluck('sku')->sort()->values()->all())->toBe(['DRS-EVE-L', 'DRS-EVE-XL'])
        ->and(ProductVariant::where('shopify_id', '2202')->value('compare_at_price'))->toBe('999.00');
});

it('re-dispatches itself with a doubling delay while the bulk operation is running', function () {
    Queue::fake();
    $this->pollFixture = 'bulk_operation_running.json';

    (new RunBulkImportStage('products'))->handle(app(BulkImporter::class));

    $stage = ShopifyIntegration::first()->import_state['stages']['products'];
    expect($stage['status'])->toBe('running')
        ->and($stage['bulk_operation_id'])->toBe($this->operationId)
        ->and($stage['total'])->toBe(120);
    Queue::assertPushed(RunBulkImportStage::class, fn ($job) => $job->stage === 'products' && $job->pollAttempt === 1 && $job->delay === 5);

    (new RunBulkImportStage('products', 3))->handle(app(BulkImporter::class));
    expect(ShopifyIntegration::first()->import_state['stages']['products']['poll_attempts'])->toBe(2)
        ->and(RunBulkImportStage::pollDelay(1))->toBe(10)
        ->and(RunBulkImportStage::pollDelay(3))->toBe(30);
    Http::assertSentCount(3); // one bulkOperationRunQuery, then only polls of the stored operation
});

it('fails the stage when the bulk operation fails', function () {
    Queue::fake();
    $failed = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/bulk_operation_running.json')), true);
    $failed['data']['node']['status'] = 'FAILED';
    $failed['data']['node']['errorCode'] = 'INTERNAL_SERVER_ERROR';
    $this->pollFixture = $failed;

    expect(fn () => (new RunBulkImportStage('products'))->handle(app(BulkImporter::class)))->toThrow(RuntimeException::class);

    expect(ShopifyIntegration::first()->import_state['stages']['products']['status'])->toBe('failed')
        ->and(ShopifySyncRun::where('resource', 'products')->value('status'))->toBe('failed');
    Queue::assertNothingPushed();
});

it('fails the stage after the polling cap', function () {
    $this->pollFixture = 'bulk_operation_running.json';
    ShopifyIntegration::first()->update(['import_state' => importState(['products' => [
        'status' => 'running', 'phase' => 'polling', 'bulk_operation_id' => 'gid://shopify/BulkOperation/720918', 'poll_attempts' => BulkImporter::MAX_POLLS,
    ]])]);

    expect(fn () => app(BulkImporter::class)->runStage('products'))->toThrow(RuntimeException::class, 'after 720 polls');
    expect(ShopifyIntegration::first()->import_state['stages']['products']['status'])->toBe('failed');
    Http::assertNotSent(fn ($r) => str_contains($r['query'] ?? '', 'bulkOperationRunQuery'));
});

it('reuses the bulk operation of a failed stage when it is still usable', function () {
    ShopifyIntegration::first()->update(['import_state' => importState(['products' => [
        'status' => 'failed', 'bulk_operation_id' => 'gid://shopify/BulkOperation/720918',
    ]])]);

    expect(app(BulkImporter::class)->runStage('products'))->toBe(BulkImporter::OUTCOME_COMPLETED)
        ->and(Product::count())->toBe(3);
    Http::assertNotSent(fn ($r) => str_contains($r['query'] ?? '', 'bulkOperationRunQuery'));
});

it('records the Shopify userErrors when the bulk operation cannot start', function () {
    $this->bulkRunResponse = ['data' => ['bulkOperationRunQuery' => ['bulkOperation' => null, 'userErrors' => [
        ['field' => null, 'message' => 'A bulk query operation for this app and shop is already in progress'],
    ]]]];

    expect(fn () => app(BulkImporter::class)->runStage('products'))->toThrow(ShopifyException::class);

    $run = ShopifySyncRun::where('resource', 'products')->firstOrFail();
    expect($run->status)->toBe('failed')
        ->and(collect($run->errors)->pluck('message')->implode(' '))->toContain('already in progress');
});

it('counts malformed JSONL lines as failed rows', function () {
    $this->productsJsonl = "{not json\n".file_get_contents(base_path('tests/Fixtures/shopify/bulk_products.jsonl'));

    app(BulkImporter::class)->runStage('products');

    $run = ShopifySyncRun::where('resource', 'products')->firstOrFail();
    expect($run->failed)->toBe(1)->and($run->errors[0]['ref'])->toBe('jsonl')
        ->and(Product::count())->toBe(3);
});

it('maps a file larger than one job budget across several steps without duplicates', function () {
    Queue::fake();
    config(['crm.shopify.import_chunk_size' => 2, 'crm.shopify.import_chunks_per_job' => 1]);
    $this->productsJsonl = file_get_contents(base_path('tests/Fixtures/shopify/bulk_products_with_variants.jsonl'));
    $importer = app(BulkImporter::class);

    (new RunBulkImportStage('products'))->handle($importer);
    Queue::assertPushed(RunBulkImportStage::class, fn ($job) => $job->stage === 'products' && $job->pollAttempt === 0 && $job->delay === null);

    $stage = ShopifyIntegration::first()->import_state['stages']['products'];
    expect($stage['status'])->toBe('running')->and($stage['phase'])->toBe('mapping')
        ->and($stage['offset'])->toBe(strpos($this->productsJsonl, '{"id":"gid://shopify/Product/2103"'))
        ->and(Product::count())->toBe(2)
        ->and(file_exists($stage['file']))->toBeTrue();

    $outcomes = [$importer->runStage('products'), $importer->runStage('products')];

    $run = ShopifySyncRun::where('resource', 'products')->sole();
    $stage = ShopifyIntegration::first()->import_state['stages']['products'];
    expect($outcomes)->toBe([BulkImporter::OUTCOME_WORKING, BulkImporter::OUTCOME_COMPLETED])
        ->and(Product::count())->toBe(5)
        ->and(ProductVariant::count())->toBe(6)
        ->and($run->processed)->toBe(5)->and($run->created)->toBe(5)->and($run->status)->toBe('completed')
        ->and($stage['processed'])->toBe(5)->and($stage['offset'])->toBeNull()
        ->and(file_exists(storage_path('app/shopify/products-'.\App\Shopify\Sync\Mappers\Payload::id($this->operationId).'.jsonl')))->toBeFalse();
    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'storage.shopifycloud.com/products'))->count())->toBe(1);
});

it('continues a partial download with a Range request in the next step', function () {
    config(['crm.shopify.import_job_seconds' => 0]);
    $lines = [];
    for ($n = 1; $n <= 900; $n++) {
        $lines[] = json_encode(['id' => 'gid://shopify/Product/'.(50000 + $n), 'title' => "منتج تجريبي رقم {$n} ".str_repeat('x', 60), 'handle' => "p-{$n}",
            'status' => 'ACTIVE', 'vendor' => 'Demo', 'productType' => null, 'tags' => [], 'updatedAt' => '2026-08-01T10:00:00Z', 'featuredImage' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $this->productsJsonl = implode("\n", $lines)."\n";
    $importer = app(BulkImporter::class);

    $outcomes = [];
    do {
        $outcomes[] = $outcome = $importer->runStage('products');
    } while ($outcome !== BulkImporter::OUTCOME_COMPLETED && count($outcomes) < 20);

    expect(end($outcomes))->toBe(BulkImporter::OUTCOME_COMPLETED)
        ->and(count($outcomes))->toBeGreaterThan(2)
        ->and(Product::count())->toBe(900)
        ->and(ShopifySyncRun::where('resource', 'products')->sole()->processed)->toBe(900);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'storage.shopifycloud.com/products') && $r->hasHeader('Range'));
});

it('refuses to start while an import chain is running', function () {
    Queue::fake();
    ShopifyIntegration::first()->update(['import_state' => importState(['products' => ['status' => 'running', 'updated_at' => now()->subMinutes(3)->toIso8601String()]])]);

    expect(fn () => app(BulkImporter::class)->start())->toThrow(ImportAlreadyRunningException::class);
    Queue::assertNothingPushed();

    // A chain silent for longer than 15 minutes is considered dead.
    ShopifyIntegration::first()->update(['import_state' => importState(['products' => ['status' => 'running', 'updated_at' => now()->subMinutes(20)->toIso8601String()]])]);
    app(BulkImporter::class)->start();
    Queue::assertPushed(RunBulkImportStage::class, fn ($job) => $job->stage === 'shipping');
});

it('does not resume a stage a live chain is still running', function () {
    Queue::fake();
    ShopifyIntegration::first()->update(['import_state' => importState([
        'shipping' => ['status' => 'completed'],
        'products' => ['status' => 'running', 'updated_at' => now()->subMinutes(2)->toIso8601String()],
    ])]);

    app(BulkImporter::class)->resume();

    Queue::assertNothingPushed();
});

it('makes a concurrent runStage of the same stage a no-op', function () {
    $held = Cache::lock('shopify-import-products', 900);
    $held->get();

    try {
        expect(app(BulkImporter::class)->runStage('products'))->toBe(BulkImporter::OUTCOME_LOCKED);
    } finally {
        $held->release();
    }

    Http::assertNothingSent();
    expect(ShopifySyncRun::count())->toBe(0);
});

it('pages shipping zones beyond the first page per profile location group', function () {
    $zones = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/shipping_zones.json')), true);
    $this->shippingResponse = bulkShippingProfilesResponse([$zones[0]], hasNextPage: true);
    $this->shippingFollowUp = ['data' => ['deliveryProfile' => ['profileLocationGroups' => [[
        'locationGroupZones' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => [$zones[1]]],
    ]]]]];

    app(BulkImporter::class)->runStage('shipping');

    expect(ShippingZone::count())->toBe(2);
    Http::assertSent(fn ($r) => ($r['variables']['locationGroupId'] ?? null) === 'gid://shopify/DeliveryLocationGroup/1' && ($r['variables']['cursor'] ?? null) === 'zones-1');
});

it('regroups JSONL children under their parent with GraphQL connection keys', function () {
    $path = storage_path('framework/testing-reader.jsonl');
    file_put_contents($path, implode("\n", [
        '{"id":"gid://shopify/Product/1","title":"A"}',
        '{"id":"gid://shopify/ProductVariant/11","title":"A-1","__parentId":"gid://shopify/Product/1"}',
        '{"id":"gid://shopify/ProductVariant/12","title":"A-2","__parentId":"gid://shopify/Product/1"}',
        '{"id":"gid://shopify/Product/2","title":"B"}',
        '{"id":"gid://shopify/Order/3"}',
        '{"id":"gid://shopify/LineItem/31","__parentId":"gid://shopify/Order/3"}',
        '',
    ]));

    $rows = iterator_to_array((new JsonlReader)->objects($path), false);
    @unlink($path);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows[0][0]['variants']['nodes'], 'id'))->toBe(['gid://shopify/ProductVariant/11', 'gid://shopify/ProductVariant/12'])
        ->and($rows[0][0]['variants']['nodes'][0])->not->toHaveKey('__parentId')
        ->and($rows[0][1]['ProductVariant'])->toHaveCount(2)
        ->and($rows[1][0])->not->toHaveKey('variants')
        ->and($rows[2][0]['lineItems']['nodes'])->toHaveCount(1);
});

it('yields parent-boundary offsets that resume at the next parent', function () {
    $path = storage_path('framework/testing-reader-offset.jsonl');
    $content = implode("\n", [
        '{"id":"gid://shopify/Product/1","title":"A"}',
        '{"id":"gid://shopify/ProductVariant/11","__parentId":"gid://shopify/Product/1"}',
        'garbage',
        '{"id":"gid://shopify/Product/2","title":"B"}',
        '{"id":"gid://shopify/ProductVariant/21","__parentId":"gid://shopify/Product/2"}',
    ])."\n";
    file_put_contents($path, $content);

    try {
        $all = iterator_to_array((new JsonlReader)->objects($path), false);
        $resumed = iterator_to_array((new JsonlReader)->objects($path, $all[1][2]), false);
    } finally {
        @unlink($path);
    }

    expect($all)->toHaveCount(3)
        ->and($all[0][0])->toHaveKey('__malformed')->and($all[0][2])->toBeNull()
        ->and($all[1][0]['id'])->toBe('gid://shopify/Product/1')
        ->and($all[1][2])->toBe(strpos($content, '{"id":"gid://shopify/Product/2"'))
        ->and($all[2][2])->toBe(strlen($content))
        ->and($resumed)->toHaveCount(1)
        ->and($resumed[0][0]['id'])->toBe('gid://shopify/Product/2')
        ->and($resumed[0][0]['variants']['nodes'])->toHaveCount(1);
});

it('imports from the fake driver catalog without a real store', function () {
    config(['crm.shopify.driver' => 'fake']);

    app(BulkImporter::class)->start();

    expect(collect(ShopifyIntegration::first()->import_state['stages'])->pluck('status')->unique()->all())->toBe(['completed'])
        ->and(ShippingZone::count())->toBeGreaterThan(0)
        ->and(Product::count())->toBeGreaterThan(0)
        ->and(Order::where('source', 'store')->count())->toBeGreaterThan(0);
    Http::assertNothingSent();
});
