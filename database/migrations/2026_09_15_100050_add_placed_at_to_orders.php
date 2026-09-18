<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer placed the order. Imported Shopify orders get Shopify's
 * created_at here (orders.created_at is the import time); chat orders leave it
 * null and fall back to created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'placed_at')) {
            Schema::table('orders', fn (Blueprint $t) => $t->timestamp('placed_at')->nullable()->after('cancelled_at'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'placed_at')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('placed_at'));
        }
    }
};
