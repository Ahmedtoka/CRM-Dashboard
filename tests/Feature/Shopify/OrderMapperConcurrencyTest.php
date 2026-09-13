<?php

use App\Models\Order;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use Illuminate\Support\Facades\DB;

/**
 * These tests need real Eloquent model events (Order::creating/retrieved), so
 * they deliberately avoid MappersTest.php's blanket Event::fake().
 */
function fixtureOrder(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/shopify/webhook_order_store.json')), true);
}

afterEach(fn () => Order::flushEventListeners());

it('checks staleness against the row read under the lock, not a value cached before a concurrent write', function () {
    $o = fixtureOrder();
    app(OrderMapper::class)->upsert($o);

    $shopifyId = (string) $o['id'];
    $raced = false;

    // Fires when this test's second upsert() call locks and re-reads the row
    // inside its transaction: simulates a concurrent, newer webhook delivery
    // committing between that lookup and the stale-timestamp check that follows.
    Order::retrieved(function (Order $model) use ($shopifyId, &$raced) {
        if ($raced || $model->shopify_order_id !== $shopifyId) {
            return;
        }
        $raced = true;

        DB::table('orders')->where('id', $model->id)->update([
            'total' => '999.00',
            'shopify_updated_at' => now()->addYear(),
        ]);
    });

    // Strictly older than both the fixture's own updated_at and the raced
    // write above, so staleness can only be explained by comparing against
    // the row actually read under the lock.
    $older = array_replace($o, ['current_total_price' => '1.00', 'updated_at' => '2020-01-01T00:00:00Z']);

    expect(app(OrderMapper::class)->upsert($older))->toBe(MapResult::Skipped)
        ->and($raced)->toBeTrue()
        ->and(Order::where('shopify_order_id', $shopifyId)->value('total'))->toBe('999.00');
});

it('recovers when a concurrent delivery wins the create race on the unique shopify_order_id index', function () {
    $o = fixtureOrder();
    $shopifyId = (string) $o['id'];
    $attempts = 0;

    // Fires when Eloquent is about to INSERT the new store order, i.e. after
    // this call's own lookup already found nothing locally: simulates another
    // worker's delivery of the same brand-new Shopify order winning the insert
    // first, landing exactly in that lookup-to-insert window.
    Order::creating(function (Order $model) use ($shopifyId, &$attempts) {
        if ($attempts > 0 || $model->shopify_order_id !== $shopifyId) {
            return;
        }
        $attempts++;

        DB::table('orders')->insert(array_merge($model->getAttributes(), [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    });

    expect(app(OrderMapper::class)->upsert($o))->toBe(MapResult::Created)
        ->and($attempts)->toBe(1)
        ->and(Order::where('shopify_order_id', $shopifyId)->count())->toBe(1);
});
