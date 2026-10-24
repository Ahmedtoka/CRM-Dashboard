<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok direct messages (x6, 2026-10-24): TikTok addresses a reply to a `conversation_id`, not to the
 * customer, so the id each inbound webhook carries is kept per business account × customer
 * (`unique_identifier`) and read back when the CRM sends. Nothing else is derived from this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_conversations', function (Blueprint $t) {
            $t->id();
            // The TikTok account's open_id (ChannelAccount.external_id of the messaging account).
            $t->string('business_id', 120);
            // The customer's unique_identifier (CustomerIdentity.external_id on the tiktok platform).
            $t->string('customer_external_id', 120);
            $t->string('conversation_id', 255);
            $t->string('customer_username', 120)->nullable();
            $t->timestamp('last_inbound_at')->nullable();
            $t->timestamps();

            $t->unique(['business_id', 'customer_external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_conversations');
    }
};
