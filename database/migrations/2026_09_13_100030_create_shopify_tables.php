<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopify_integrations')) {
            Schema::create('shopify_integrations', function (Blueprint $table) {
                $table->id();
                $table->string('shop_domain');
                $table->string('shop_name')->nullable();
                $table->string('currency', 10)->nullable();
                $table->text('access_token')->nullable();
                $table->text('api_secret')->nullable();
                $table->string('api_version')->nullable();
                $table->json('granted_scopes')->nullable();
                $table->string('status', 20)->default('disconnected');
                $table->text('last_error')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->json('settings')->nullable();
                $table->json('import_state')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shopify_webhook_subscriptions')) {
            Schema::create('shopify_webhook_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->string('topic')->unique();
                $table->string('shopify_subscription_id')->nullable();
                $table->string('callback_url')->nullable();
                $table->timestamp('last_received_at')->nullable();
                $table->timestamp('registered_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shipping_zones')) {
            Schema::create('shipping_zones', function (Blueprint $table) {
                $table->id();
                $table->string('shopify_zone_id')->nullable();
                $table->string('name');
                $table->json('countries')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shipping_zone_regions')) {
            Schema::create('shipping_zone_regions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
                $table->string('country_code', 2);
                $table->string('province_code')->nullable();
                $table->string('province_name')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shipping_rates')) {
            Schema::create('shipping_rates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
                $table->string('shopify_rate_id')->nullable();
                $table->string('title')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('min_order_subtotal', 12, 2)->nullable();
                $table->decimal('max_order_subtotal', 12, 2)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_addresses')) {
            Schema::create('customer_addresses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('shopify_address_id')->nullable();
                $table->string('name')->nullable();
                $table->string('phone')->nullable();
                $table->string('address1')->nullable();
                $table->string('address2')->nullable();
                $table->string('city')->nullable();
                $table->string('province')->nullable();
                $table->string('province_code')->nullable();
                $table->string('zip')->nullable();
                $table->string('country_code', 2)->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fulfillments')) {
            Schema::create('fulfillments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->string('shopify_fulfillment_id')->nullable()->unique();
                $table->string('status')->nullable();
                $table->string('tracking_company')->nullable();
                $table->string('tracking_number')->nullable();
                $table->string('tracking_url')->nullable();
                $table->string('shipment_status')->nullable();
                $table->timestamp('shopify_created_at')->nullable();
                $table->timestamp('shopify_updated_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->string('shopify_refund_id')->nullable()->unique();
                $table->decimal('amount', 12, 2)->default(0);
                $table->text('note')->nullable();
                $table->boolean('restock')->default(false);
                $table->timestamp('shopify_created_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shopify_sync_runs')) {
            Schema::create('shopify_sync_runs', function (Blueprint $table) {
                $table->id();
                $table->string('type', 30);
                $table->string('resource', 30);
                $table->timestamp('range_from')->nullable();
                $table->timestamp('range_to')->nullable();
                $table->string('status', 20)->default('running');
                $table->unsignedInteger('processed')->default(0);
                $table->unsignedInteger('created')->default(0);
                $table->unsignedInteger('updated')->default(0);
                $table->unsignedInteger('skipped_stale')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->json('errors')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_sync_runs');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('fulfillments');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zone_regions');
        Schema::dropIfExists('shipping_zones');
        Schema::dropIfExists('shopify_webhook_subscriptions');
        Schema::dropIfExists('shopify_integrations');
    }
};
