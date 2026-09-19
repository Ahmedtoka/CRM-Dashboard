<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange links (2026-09-19): the bot finds the product she links to by its
 * Shopify handle (`/products/{handle}`), so the synced `products.handle` is indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'handle') || Schema::hasIndex('products', 'products_handle_index')) {
            return;
        }

        Schema::table('products', function (Blueprint $t) {
            $t->index('handle');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasIndex('products', 'products_handle_index')) {
            Schema::table('products', function (Blueprint $t) {
                $t->dropIndex('products_handle_index');
            });
        }
    }
};
