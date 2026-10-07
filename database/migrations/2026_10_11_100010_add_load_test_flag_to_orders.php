<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production load test, review round 1 (2026-10-07): an order taken in a load-test chat is a test
 * order (`is_load_test`). It is sent to the load-test commerce provider, never to Shopify, and the
 * Order model's global scope keeps it out of every report, sync and lookup. Guarded; down() drops
 * only this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'is_load_test')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->boolean('is_load_test')->default(false);
                $t->index('is_load_test', 'orders_is_load_test_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'is_load_test')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->dropIndex('orders_is_load_test_idx');
                $t->dropColumn('is_load_test');
            });
        }
    }
};
