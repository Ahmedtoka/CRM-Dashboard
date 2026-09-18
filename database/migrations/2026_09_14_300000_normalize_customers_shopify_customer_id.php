<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up to 2026_09_13_100010_extend_customers_for_shopify (final fix wave):
 * a database whose customers carried blank ('') or duplicate
 * shopify_customer_id values could not get the unique index. Where the index
 * is missing, blanks become NULL, duplicates keep only the lowest customer id,
 * and the index is created. A no-op wherever the index already exists.
 */
return new class extends Migration
{
    private const INDEX = 'customers_shopify_customer_id_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'shopify_customer_id') || $this->indexExists()) {
            return;
        }

        DB::table('customers')
            ->whereNotNull('shopify_customer_id')
            ->whereRaw("TRIM(shopify_customer_id) = ''")
            ->update(['shopify_customer_id' => null]);

        $duplicates = DB::table('customers')
            ->whereNotNull('shopify_customer_id')
            ->groupBy('shopify_customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('shopify_customer_id');

        foreach ($duplicates as $shopifyId) {
            $keep = DB::table('customers')->where('shopify_customer_id', $shopifyId)->min('id');

            DB::table('customers')
                ->where('shopify_customer_id', $shopifyId)
                ->where('id', '!=', $keep)
                ->update(['shopify_customer_id' => null]);
        }

        Schema::table('customers', fn ($table) => $table->unique('shopify_customer_id'));
    }

    /**
     * The index belongs to 100010's down(); nothing to undo here (nulled values
     * were invalid duplicates and are not restored).
     */
    public function down(): void {}

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes('customers'))->contains(fn ($i) => $i['name'] === self::INDEX);
    }
};
