<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp order confirmation (spec 2026-10-16): one row per Shopify order that
 * was considered for a confirmation, plus a small key/value settings table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_e164', 20)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->string('skip_reason', 40)->nullable();
            $table->timestamp('send_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('answer', 10)->nullable();
            $table->text('edit_note')->nullable();
            $table->timestamp('edit_note_requested_at')->nullable();
            $table->foreignId('case_id')->nullable()->constrained('support_cases')->nullOnDelete();
            $table->foreignId('outbound_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('reminder_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('last_error', 500)->nullable();
            $table->json('pending_tags')->nullable();
            $table->timestamp('tag_synced_at')->nullable();
            $table->string('call_outcome', 20)->nullable();
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('called_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
            $table->index(['status', 'sent_at']);
            $table->index('phone_e164');
        });

        Schema::create('order_confirmation_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_confirmation_settings');
        Schema::dropIfExists('order_confirmations');
    }
};
