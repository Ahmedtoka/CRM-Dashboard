<?php

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final fix wave minor: blank and duplicate shopify_customer_id values must be
 * normalised before the unique index is (re)created, and the follow-up
 * migration is a no-op when the index already exists.
 */
function customersShopifyIdIndexExists(): bool
{
    return collect(Schema::getIndexes('customers'))->contains(fn ($i) => $i['name'] === 'customers_shopify_customer_id_unique');
}

function runShopifyIdNormalizationMigration(): void
{
    (require database_path('migrations/2026_09_14_300000_normalize_customers_shopify_customer_id.php'))->up();
}

it('nulls blank and duplicate shopify customer ids, keeping the lowest id, then adds the unique index', function () {
    Schema::table('customers', fn ($t) => $t->dropUnique('customers_shopify_customer_id_unique'));
    expect(customersShopifyIdIndexExists())->toBeFalse();

    $a = Customer::factory()->create();
    $b = Customer::factory()->create();
    $c = Customer::factory()->create();
    $d = Customer::factory()->create();
    $e = Customer::factory()->create();

    DB::table('customers')->where('id', $a->id)->update(['shopify_customer_id' => '555']);
    DB::table('customers')->where('id', $b->id)->update(['shopify_customer_id' => '555']);
    DB::table('customers')->where('id', $c->id)->update(['shopify_customer_id' => '']);
    DB::table('customers')->where('id', $d->id)->update(['shopify_customer_id' => '  ']);
    DB::table('customers')->where('id', $e->id)->update(['shopify_customer_id' => '666']);

    runShopifyIdNormalizationMigration();

    $ids = DB::table('customers')->pluck('shopify_customer_id', 'id');

    expect($ids[$a->id])->toBe('555')
        ->and($ids[$b->id])->toBeNull()
        ->and($ids[$c->id])->toBeNull()
        ->and($ids[$d->id])->toBeNull()
        ->and($ids[$e->id])->toBe('666')
        ->and(customersShopifyIdIndexExists())->toBeTrue();
});

it('is a no-op when the unique index already exists', function () {
    $a = Customer::factory()->create();
    DB::table('customers')->where('id', $a->id)->update(['shopify_customer_id' => '']);

    runShopifyIdNormalizationMigration();

    expect(DB::table('customers')->where('id', $a->id)->value('shopify_customer_id'))->toBe('')
        ->and(customersShopifyIdIndexExists())->toBeTrue();
});
