<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ads round 3 (2026-10-24). Orders: the ad set an order is attributed to (utm_term carries the Meta ad set id on
     * 4,518 of the live copy's 7,413 orders) and «تكلفة الإعلان لكل أوردر» (the attributed object's spend with tax on the
     * order's Cairo day, split over that object's orders of the day). Materials: the low-stock episode flag (threshold
     * ads_settings.stock_low_threshold), beside the out-of-stock flag need_stop_at.
     */
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'ad_set_id')) {
                    $table->foreignId('ad_set_id')->nullable()->after('ad_campaign_id')->constrained('ad_sets')->nullOnDelete();
                }
                if (! Schema::hasColumn('orders', 'ad_cost_per_order')) {
                    $table->decimal('ad_cost_per_order', 12, 2)->nullable()->after('ad_attribution');
                }
            });
            if (! Schema::hasIndex('orders', 'orders_ad_set_id_index')) {
                Schema::table('orders', fn (Blueprint $table) => $table->index('ad_set_id', 'orders_ad_set_id_index'));
            }
        }
        if (Schema::hasTable('ad_materials') && ! Schema::hasColumn('ad_materials', 'low_stock_at')) {
            Schema::table('ad_materials', fn (Blueprint $table) => $table->timestamp('low_stock_at')->nullable()->after('need_stop_at'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (Schema::hasColumn('orders', 'ad_set_id')) {
                    $table->dropConstrainedForeignId('ad_set_id');
                }
                if (Schema::hasColumn('orders', 'ad_cost_per_order')) {
                    $table->dropColumn('ad_cost_per_order');
                }
            });
        }
        if (Schema::hasTable('ad_materials') && Schema::hasColumn('ad_materials', 'low_stock_at')) {
            Schema::table('ad_materials', fn (Blueprint $table) => $table->dropColumn('low_stock_at'));
        }
    }
};
