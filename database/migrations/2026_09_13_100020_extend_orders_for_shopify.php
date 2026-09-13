<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'source')) {
                // Existing rows backfill to 'chat' automatically via the column default.
                $table->string('source', 20)->default('chat')->index()->after('type');
            }
            if (! Schema::hasColumn('orders', 'shopify_order_name')) {
                $table->string('shopify_order_name')->nullable()->after('shopify_draft_order_id');
            }
            if (! Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('orders', 'cancel_reason')) {
                $table->string('cancel_reason')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('orders', 'shopify_updated_at')) {
                $table->timestamp('shopify_updated_at')->nullable()->after('cancel_reason');
            }
            if (! Schema::hasColumn('orders', 'idempotency_key')) {
                $table->string('idempotency_key')->nullable()->unique()->after('shopify_updated_at');
            }
            if (! Schema::hasColumn('orders', 'submit_attempts')) {
                $table->unsignedInteger('submit_attempts')->default(0)->after('idempotency_key');
            }
            if (! Schema::hasColumn('orders', 'last_error')) {
                $table->text('last_error')->nullable()->after('submit_attempts');
            }
            if (! Schema::hasColumn('orders', 'shipping_rate_id')) {
                $table->unsignedBigInteger('shipping_rate_id')->nullable()->index()->after('last_error');
            }
            if (! Schema::hasColumn('orders', 'shipping_title')) {
                $table->string('shipping_title')->nullable()->after('shipping_rate_id');
            }
            if (! Schema::hasColumn('orders', 'discount_reason')) {
                $table->string('discount_reason')->nullable()->after('discount');
            }
            if (! Schema::hasColumn('orders', 'mismatch')) {
                $table->boolean('mismatch')->default(false)->index()->after('shipping_title');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'shopify_line_item_id')) {
                $table->string('shopify_line_item_id')->nullable()->after('variant_id');
            }
            // `sku` already exists on this table.
            if (! Schema::hasColumn('order_items', 'image_url')) {
                $table->string('image_url')->nullable()->after('sku');
            }
            if (! Schema::hasColumn('order_items', 'discount')) {
                $table->decimal('discount', 12, 2)->default(0)->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = array_filter([
                'source', 'shopify_order_name', 'cancelled_at', 'cancel_reason', 'shopify_updated_at',
                'idempotency_key', 'submit_attempts', 'last_error', 'shipping_rate_id', 'shipping_title',
                'discount_reason', 'mismatch',
            ], fn (string $column) => Schema::hasColumn('orders', $column));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            $columns = array_filter(
                ['shopify_line_item_id', 'image_url', 'discount'],
                fn (string $column) => Schema::hasColumn('order_items', $column)
            );

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
