<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dedupeShopifyOrderIds();

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasIndex('orders', 'orders_shopify_order_id_unique')) {
                $table->unique('shopify_order_id');
            }
            if (! Schema::hasIndex('orders', 'orders_shopify_draft_order_id_index')) {
                $table->index('shopify_draft_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasIndex('orders', 'orders_shopify_order_id_unique')) {
                $table->dropUnique('orders_shopify_order_id_unique');
            }
            if (Schema::hasIndex('orders', 'orders_shopify_draft_order_id_index')) {
                $table->dropIndex('orders_shopify_draft_order_id_index');
            }
        });
    }

    /**
     * A unique index on shopify_order_id would fail to apply over data that
     * predates it. Rather than deleting real orders (they carry customer/item/
     * financial history), the losing duplicates — every row but the lowest id —
     * have their shopify_order_id cleared, breaking the erroneous duplicate link
     * while keeping the order itself. groupBy/havingRaw only (standard SQL), so
     * this runs the same on SQLite and MariaDB.
     */
    private function dedupeShopifyOrderIds(): void
    {
        $duplicateShopifyIds = DB::table('orders')
            ->select('shopify_order_id')
            ->whereNotNull('shopify_order_id')
            ->groupBy('shopify_order_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('shopify_order_id');

        foreach ($duplicateShopifyIds as $shopifyOrderId) {
            $ids = DB::table('orders')
                ->where('shopify_order_id', $shopifyOrderId)
                ->orderBy('id')
                ->pluck('id');

            // Keep the first (lowest id) linked; clear the rest.
            $clearIds = $ids->slice(1)->values()->all();

            if ($clearIds === []) {
                continue;
            }

            DB::table('orders')->whereIn('id', $clearIds)->update(['shopify_order_id' => null]);
        }
    }
};
