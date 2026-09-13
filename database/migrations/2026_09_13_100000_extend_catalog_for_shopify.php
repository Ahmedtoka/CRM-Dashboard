<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'vendor')) {
                $table->string('vendor')->nullable()->after('handle');
            }
            if (! Schema::hasColumn('products', 'product_type')) {
                $table->string('product_type')->nullable()->after('vendor');
            }
            if (! Schema::hasColumn('products', 'tags')) {
                $table->json('tags')->nullable()->after('product_type');
            }
            if (! Schema::hasColumn('products', 'shopify_updated_at')) {
                $table->timestamp('shopify_updated_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('products', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('shopify_updated_at');
            }
            if (! Schema::hasColumn('products', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variants', 'compare_at_price')) {
                $table->decimal('compare_at_price', 12, 2)->nullable()->after('price');
            }
            if (! Schema::hasColumn('product_variants', 'barcode')) {
                $table->string('barcode')->nullable()->after('sku');
            }
            if (! Schema::hasColumn('product_variants', 'inventory_item_id')) {
                $table->string('inventory_item_id')->nullable()->after('inventory_quantity');
            }
            if (! Schema::hasColumn('product_variants', 'inventory_policy')) {
                $table->string('inventory_policy', 20)->default('deny')->after('inventory_item_id');
            }
            if (! Schema::hasColumn('product_variants', 'requires_shipping')) {
                $table->boolean('requires_shipping')->default(true)->after('inventory_policy');
            }
            if (! Schema::hasColumn('product_variants', 'shopify_updated_at')) {
                $table->timestamp('shopify_updated_at')->nullable()->after('requires_shipping');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = array_filter(
                ['vendor', 'product_type', 'tags', 'shopify_updated_at', 'synced_at'],
                fn (string $column) => Schema::hasColumn('products', $column)
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
            if (Schema::hasColumn('products', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $columns = array_filter(
                ['compare_at_price', 'barcode', 'inventory_item_id', 'inventory_policy', 'requires_shipping', 'shopify_updated_at'],
                fn (string $column) => Schema::hasColumn('product_variants', $column)
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
