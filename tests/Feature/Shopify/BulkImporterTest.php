<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\ShopifySyncRun;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunBulkImportStage;
use App\Shopify\Sync\BulkImporter;
use App\Shopify\Sync\JsonlReader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The shipping stage reads `deliveryProfiles`; shipping_zones.json holds the bare
 * `locationGroupZones` nodes, so wrap them in the GraphQL response envelope.
 */
function bulkShippingProfilesResponse(): array
{
    $zones = json_decode(file_get_contents(base_path('tests/Fixtures/shopify/shipping_zones.json')), true);

    return ['data' => ['deliveryProfiles' => [
        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        'nodes' => [['profileLocationGroups' => [['locationGroupZones' => ['nodes' => $zones]]]]],
    ]]];
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
    $stage = 'products';

    Http::fake([
        'storage.shopifycloud.com/products*' => fn () => Http::response($this->productsJsonl),
        'storage.shopifycloud.com/customers*' => fn () => Http::response($fx('bulk_customers.jsonl')),
        'storage.shopifycloud.com/orders*' => fn () => Http::response($fx('bulk_orders.jsonl')),
        'demo.myshopify.com/*' => function ($req) use ($fx, &$stage) {
            $q = $req['query'] ?? '';
            if (str_contains($q, 'deliveryProfiles')) {
                return Http::response(bulkShippingProfilesResponse());
            }
            if (str_contains($q, 'bulkOperationRunQuery')) {
                $stage = str_contains($q, 'orders') ? 'orders' : (str_contains($q, 'customers') ? 'customers' : 'products');

                return Http::response($fx('bulk_operation_created.json'));
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

it('re-dispatches itself with a doubling delay while the bulk operation is running', function () {
    Queue::fake();
    $this->pollFixture = 'bulk_operation_running.json';

    (new RunBulkImportStage('products'))->handle(app(BulkImporter::class));

    $stage = ShopifyIntegration::first()->import_state['stages']['products'];
    expect($stage['status'])->toBe('running')
        ->and($stage['bulk_operation_id'])->toBe('gid://shopify/BulkOperation/720918')
        ->and($stage['total'])->toBe(120);
    Queue::assertPushed(RunBulkImportStage::class, fn ($job) => $job->stage === 'products' && $job->delay === 5);

    (new RunBulkImportStage('products', 3))->handle(app(BulkImporter::class));
    Queue::assertPushed(RunBulkImportStage::class, fn ($job) => $job->pollAttempt === 4 && $job->delay === 30);
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

it('imports from the fake driver catalog without a real store', function () {
    config(['crm.shopify.driver' => 'fake']);

    app(BulkImporter::class)->start();

    expect(collect(ShopifyIntegration::first()->import_state['stages'])->pluck('status')->unique()->all())->toBe(['completed'])
        ->and(ShippingZone::count())->toBeGreaterThan(0)
        ->and(Product::count())->toBeGreaterThan(0)
        ->and(Order::where('source', 'store')->count())->toBeGreaterThan(0);
    Http::assertNothingSent();
});
