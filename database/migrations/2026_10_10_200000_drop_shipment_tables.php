<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh-orders F4: the CRM no longer ships or tracks shipments (fulfilment runs in another system; Shopify's
 * fulfillments and orders.shipment_status stay). Guarded both ways; down() recreates the minimal schema only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipments')) {
            Schema::create('shipments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('carrier')->nullable();
                $table->string('tracking_number')->nullable()->index();
                $table->string('status', 30)->default('created');
                $table->timestamp('last_event_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shipment_events')) {
            Schema::create('shipment_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
                $table->string('status', 30);
                $table->string('description')->nullable();
                $table->string('location')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();
            });
        }
    }
};
