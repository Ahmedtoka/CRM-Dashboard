<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global search indexes (Dashboard Experience Task 13, spec §5.3): plain
 * indexes for the exact-match order/tracking/SKU lookups, plus a MariaDB
 * FULLTEXT index on `messages.body` for natural-language message search.
 *
 * Guarded so it's safe to re-run: skips any index that already exists, and
 * only adds the FULLTEXT index on MySQL/MariaDB (never SQLite, used in
 * tests, where GlobalSearch falls back to a plain LIKE scan).
 *
 * Note for staging: `ALTER TABLE ... ADD FULLTEXT` on a large `messages`
 * table rebuilds it — run this migration off-peak there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $has = fn (string $table, string $name) => collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $name);

        Schema::table('orders', function (Blueprint $t) use ($has) {
            if (! $has('orders', 'orders_shopify_order_name_index')) {
                $t->index('shopify_order_name');
            }
            if (! $has('orders', 'orders_order_number_index')) {
                $t->index('order_number');
            }
        });

        Schema::table('shipments', function (Blueprint $t) use ($has) {
            if (! $has('shipments', 'shipments_tracking_number_index')) {
                $t->index('tracking_number');
            }
        });

        Schema::table('product_variants', function (Blueprint $t) use ($has) {
            if (! $has('product_variants', 'product_variants_sku_index')) {
                $t->index('sku');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && ! $has('messages', 'messages_body_fulltext')) {
            DB::statement('ALTER TABLE messages ADD FULLTEXT INDEX messages_body_fulltext (body)');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE messages DROP INDEX messages_body_fulltext');
        }

        Schema::table('product_variants', fn (Blueprint $t) => $t->dropIndex(['sku']));
        Schema::table('shipments', fn (Blueprint $t) => $t->dropIndex(['tracking_number']));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['shopify_order_name']);
            $t->dropIndex(['order_number']);
        });
    }
};
