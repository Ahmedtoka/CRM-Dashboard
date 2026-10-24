<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping and tracking from Shopify only (round 2026-10-24, x4): every delivery status Shopify tells us about a
 * fulfillment — a `fulfillment_events/create` webhook, a `shipment_status` change on `fulfillments/update`, or
 * the sync's fulfillment events — becomes one row, so the order page, the thread card and the bot can show the
 * shipping timeline (created → in transit → out for delivery → delivered / failed / returned). Guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fulfillment_events')) {
            return;
        }

        Schema::create('fulfillment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfillment_id')->constrained('fulfillments')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // Shopify's own FulfillmentEvent id when the row came from one (webhook or sync); null for a status change.
            $table->string('shopify_event_id', 64)->nullable()->unique();
            // Shopify's status word, lower-cased (REST shipment_status / FulfillmentEventStatus): in_transit, delivered …
            $table->string('status', 40);
            $table->string('message', 500)->nullable();
            $table->timestamp('happened_at');
            // webhook_event | fulfillment (a shipment_status change) | sync
            $table->string('source', 20);
            $table->timestamps();
            $table->index(['order_id', 'happened_at']);
            $table->index(['fulfillment_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_events');
    }
};
