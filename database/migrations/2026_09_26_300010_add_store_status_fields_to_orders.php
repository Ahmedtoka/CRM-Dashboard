<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The latest store state of a Shopify order in its own columns, so orders can be
 * counted and filtered by governorate, payment method and delivery progress:
 * shipping_province (the governorate, e.g. "Giza"), payment_gateway (e.g.
 * "Cash on Delivery (COD)"), tags, and the latest fulfillment's shipment status
 * (in_transit, out_for_delivery, delivered, …) with its delivery time.
 * placed_at is indexed for the per-day reconciliation against Shopify.
 */
return new class extends Migration
{
    private const COLUMNS = ['shipping_province', 'payment_gateway', 'tags', 'shipment_status', 'delivered_at'];

    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'shipping_province')) {
                $t->string('shipping_province', 120)->nullable()->after('shipping_city');
            }
            if (! Schema::hasColumn('orders', 'payment_gateway')) {
                $t->string('payment_gateway', 191)->nullable()->after('type');
            }
            if (! Schema::hasColumn('orders', 'tags')) {
                $t->text('tags')->nullable()->after('note');
            }
            if (! Schema::hasColumn('orders', 'shipment_status')) {
                $t->string('shipment_status', 40)->nullable()->after('fulfillment_status');
            }
            if (! Schema::hasColumn('orders', 'delivered_at')) {
                $t->timestamp('delivered_at')->nullable()->after('shipment_status');
            }
        });

        if (! Schema::hasIndex('orders', 'orders_placed_at_index')) {
            Schema::table('orders', fn (Blueprint $t) => $t->index('placed_at'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasIndex('orders', 'orders_placed_at_index')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('orders_placed_at_index'));
        }

        $existing = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('orders', $c)));

        if ($existing !== []) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn($existing));
        }
    }
};
