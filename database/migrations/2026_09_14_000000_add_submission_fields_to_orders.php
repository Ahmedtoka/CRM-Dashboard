<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The submission job re-reads everything from the local order (never from the
 * request or Shopify), so the governorate code and the discount type/value the
 * moderator chose must be stored alongside the computed amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'shipping_province_code')) {
                $table->string('shipping_province_code', 10)->nullable()->after('shipping_city');
            }
            if (! Schema::hasColumn('orders', 'discount_type')) {
                $table->string('discount_type', 10)->nullable()->after('discount');
            }
            if (! Schema::hasColumn('orders', 'discount_value')) {
                $table->decimal('discount_value', 12, 2)->nullable()->after('discount_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = array_filter(
                ['shipping_province_code', 'discount_type', 'discount_value'],
                fn (string $column) => Schema::hasColumn('orders', $column)
            );

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
