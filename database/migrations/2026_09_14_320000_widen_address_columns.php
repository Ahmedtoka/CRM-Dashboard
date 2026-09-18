<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real Shopify addresses (full Arabic street + landmark descriptions) exceed
 * 255 characters; the first live import rejected a customer and an order with
 * "Data too long". Addresses are never indexed, so text is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->text('address')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->text('shipping_address')->nullable()->change();
        });

        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->text('address1')->nullable()->change();
            $table->text('address2')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('address')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_address')->nullable()->change();
        });

        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->string('address1')->nullable()->change();
            $table->string('address2')->nullable()->change();
        });
    }
};
