<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every ad referral of a conversation (A4, R-08), append-only: the conversation row keeps its first ad, this table
 * keeps them all, so an order is credited to the latest ad before it. No DDL on `conversations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_ad_referrals')) {
            return;
        }

        Schema::create('conversation_ad_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('ad_external_id', 64);
            $table->dateTime('referred_at');
            $table->unique(['conversation_id', 'ad_external_id', 'referred_at'], 'conv_ad_referrals_unique');
            $table->index(['customer_id', 'referred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_ad_referrals');
    }
};
