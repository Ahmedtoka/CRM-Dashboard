<?php

use App\Models\BotSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order-aware returns (spec 2026-09-19 §2, §4): the owner-editable list of
 * words that mark an item as never returnable, plus the Shopify fields the
 * item picker needs that were not synced yet — the line's variant title
 * (colour/size), the order's billing phone (ownership check) and the
 * fulfillment's delivery time (14-day window).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bot_settings') && ! Schema::hasColumn('bot_settings', 'non_returnable_keywords')) {
            Schema::table('bot_settings', function (Blueprint $table) {
                $table->json('non_returnable_keywords')->nullable();
            });

            DB::table('bot_settings')->whereNull('non_returnable_keywords')->update([
                'non_returnable_keywords' => json_encode(BotSetting::DEFAULT_NON_RETURNABLE_KEYWORDS, JSON_UNESCAPED_UNICODE),
            ]);
        }

        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'variant_title')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('variant_title')->nullable()->after('title');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'billing_phone')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('billing_phone', 50)->nullable()->after('shipping_phone');
            });
        }

        if (Schema::hasTable('fulfillments') && ! Schema::hasColumn('fulfillments', 'delivered_at')) {
            Schema::table('fulfillments', function (Blueprint $table) {
                $table->timestamp('delivered_at')->nullable()->after('shipment_status');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'bot_settings' => 'non_returnable_keywords',
            'order_items' => 'variant_title',
            'orders' => 'billing_phone',
            'fulfillments' => 'delivered_at',
        ] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
