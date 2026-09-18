<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §6.1/§6.3: realized-revenue rollup columns on analytics_daily, plus the
 * stored mismatch reason and the reason supervisors were last notified about
 * on orders (notify once per order per reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_daily', function (Blueprint $table) {
            if (! Schema::hasColumn('analytics_daily', 'orders_delivered')) {
                $table->unsignedInteger('orders_delivered')->default(0)->after('orders_total');
            }
            if (! Schema::hasColumn('analytics_daily', 'revenue_realized')) {
                // Signed: a refund-only day is negative.
                $table->decimal('revenue_realized', 12, 2)->default(0)->after('orders_delivered');
            }
            if (! Schema::hasColumn('analytics_daily', 'orders_returned')) {
                $table->unsignedInteger('orders_returned')->default(0)->after('revenue_realized');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'mismatch_reason')) {
                $table->string('mismatch_reason', 40)->nullable()->after('mismatch');
            }
            if (! Schema::hasColumn('orders', 'mismatch_notified_reason')) {
                $table->string('mismatch_notified_reason', 40)->nullable()->after('mismatch_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('analytics_daily', function (Blueprint $table) {
            $columns = array_filter(
                ['orders_delivered', 'revenue_realized', 'orders_returned'],
                fn (string $column) => Schema::hasColumn('analytics_daily', $column)
            );

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $columns = array_filter(
                ['mismatch_reason', 'mismatch_notified_reason'],
                fn (string $column) => Schema::hasColumn('orders', $column)
            );

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
