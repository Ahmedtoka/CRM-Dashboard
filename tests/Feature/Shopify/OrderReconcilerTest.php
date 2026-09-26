<?php

use App\Enums\OrderSource;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Jobs\RunBulkImportStage;
use App\Shopify\Sync\OrderReconciler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Shopify answers ordersCount from $counts: the first entry whose needle is in
 * the search query wins (status filters are listed before the plain day ranges),
 * and the day's order list from $orders.
 */
function fakeShopifyCounts(array $counts, array $orders = []): void
{
    Http::fake(['demo.myshopify.com/*' => function ($req) use ($counts, $orders) {
        $query = (string) ($req['variables']['q'] ?? '');

        if (str_contains($req['query'] ?? '', 'ordersCount')) {
            foreach ($counts as $needle => $count) {
                if (str_contains($query, $needle)) {
                    return Http::response(['data' => ['ordersCount' => ['count' => $count, 'precision' => 'EXACT']]]);
                }
            }

            return Http::response(['data' => ['ordersCount' => ['count' => 0, 'precision' => 'EXACT']]]);
        }

        return Http::response(['data' => ['orders' => [
            'edges' => array_map(fn ($o) => ['node' => $o], $orders),
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ]]]);
    }]);
}

function storeOrder(string $id, string $placedAtCairo, array $extra = []): Order
{
    $customer = Customer::create(['name' => "C{$id}"]);

    return Order::create(array_merge([
        'customer_id' => $customer->id,
        'source' => OrderSource::Store,
        'type' => 'cod',
        'status' => 'confirmed',
        'shopify_order_id' => $id,
        'shopify_order_name' => "#{$id}",
        'financial_status' => 'pending',
        'fulfillment_status' => null,
        'placed_at' => \Carbon\CarbonImmutable::parse($placedAtCairo, 'Africa/Cairo')->utc(),
        'subtotal' => 100, 'shipping_fee' => 0, 'discount' => 0, 'total' => 100,
    ], $extra));
}

beforeEach(function () {
    config(['crm.shopify.driver' => 'live']);
    ShopifyIntegration::create(['shop_domain' => 'demo.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
});

it('matches when every shop-local day and status agrees', function () {
    // 00:30 Cairo on the 2nd is still the 1st in UTC: it must count on the 2nd.
    storeOrder('1', '2026-09-01 10:00');
    storeOrder('2', '2026-09-02 00:30');

    fakeShopifyCounts([
        'financial_status:pending' => 2,
        'fulfillment_status:unshipped' => 2,
        'status:cancelled' => 0,
        'financial_status:' => 0,
        'fulfillment_status:' => 0,
        "created_at:>='2026-09-01T00:00:00+03:00' AND created_at:<'2026-09-02T00:00:00+03:00'" => 1,
        "created_at:>='2026-09-02T00:00:00+03:00' AND created_at:<'2026-09-03T00:00:00+03:00'" => 1,
    ]);

    $result = app(OrderReconciler::class)->compare('2026-09-01', '2026-09-02');

    expect($result['matched'])->toBeTrue()
        ->and($result['totals'])->toBe(['shopify' => 2, 'crm' => 2, 'diff' => 0])
        ->and(array_column($result['days'], 'crm'))->toBe([1, 1])
        ->and($result['missing'])->toBe([]);
});

it('names the orders missing from the CRM and the ones Shopify no longer lists', function () {
    storeOrder('1', '2026-09-01 10:00');
    storeOrder('9', '2026-09-01 11:00');

    fakeShopifyCounts(['created_at:' => 3], [
        ['id' => 'gid://shopify/Order/1', 'name' => '#1'],
        ['id' => 'gid://shopify/Order/2', 'name' => '#2'],
        ['id' => 'gid://shopify/Order/3', 'name' => '#3'],
    ]);

    $result = app(OrderReconciler::class)->compare('2026-09-01', '2026-09-01');

    expect($result['matched'])->toBeFalse()
        ->and(array_column($result['missing'], 'name'))->toBe(['#2', '#3'])
        ->and(array_column($result['extra'], 'name'))->toBe(['#9']);
});

it('flags a status that is out of date even when the count matches', function () {
    storeOrder('1', '2026-09-01 10:00', ['financial_status' => 'pending']);

    fakeShopifyCounts(['financial_status:paid' => 1, 'financial_status:' => 0, 'fulfillment_status:unshipped' => 1, 'fulfillment_status:' => 0, 'status:' => 0, 'created_at:' => 1]);

    $result = app(OrderReconciler::class)->compare('2026-09-01', '2026-09-01', withMissing: false);
    $paid = collect($result['statuses'])->firstWhere('key', 'paid');

    expect($result['matched'])->toBeFalse()
        ->and($result['totals']['diff'])->toBe(0)
        ->and($paid)->toMatchArray(['shopify' => 1, 'crm' => 0, 'diff' => -1]);
});

it('runs from the admin page, keeps the last result and imports a range through the bulk importer', function () {
    Queue::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
    fakeShopifyCounts([]);

    $this->actingAs($supervisor)->get('/settings/shopify/reconcile')->assertForbidden();

    $this->actingAs($admin)->postJson('/settings/shopify/reconcile', ['from' => '2026-09-01', 'to' => '2026-12-31'])->assertStatus(422);
    $this->actingAs($admin)->postJson('/settings/shopify/reconcile', ['from' => '2026-09-01', 'to' => '2026-09-03'])
        ->assertOk()
        ->assertJsonPath('result.matched', true)
        ->assertJsonCount(3, 'result.days');

    $this->actingAs($admin)->get('/settings/shopify/reconcile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/ShopifyReconcile')->where('result.from', '2026-09-01')->where('connected', true));

    $this->actingAs($admin)->postJson('/settings/shopify/sync', ['resource' => 'orders', 'from' => '2026-09-01', 'to' => '2026-09-03'])->assertOk();

    Queue::assertPushed(RunBulkImportStage::class, fn ($job) => $job->stage === 'orders');
    expect(ShopifyIntegration::first()->import_state['orders_until'])->toBe('2026-09-03T23:59:59+03:00');
});

it('saves the API secret on a connected store without sending it back', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    ShopifyIntegration::first()->update(['api_secret' => null]);

    $this->actingAs($admin)->get('/settings/shopify')
        ->assertInertia(fn ($page) => $page->where('integration.has_webhook_secret', false));

    $this->actingAs($admin)->putJson('/settings/shopify/secret', ['api_secret' => 'shpss_1234567890abcdef'])->assertOk();

    expect(ShopifyIntegration::first()->api_secret)->toBe('shpss_1234567890abcdef');
    $this->actingAs($admin)->get('/settings/shopify')
        ->assertInertia(fn ($page) => $page->where('integration.has_webhook_secret', true))
        ->assertDontSee('shpss_1234567890abcdef');
});
