<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «رصيد حساب Anthropic» (b2, 2026-10-19): Anthropic has no API for the prepaid credit balance, so
 * the owner types it (and the day he read it) in «التكلفة»; the projection subtracts the bot's own
 * recorded spend since then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $t) {
            $t->decimal('anthropic_balance_usd', 10, 2)->nullable();
            $t->timestamp('anthropic_balance_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $t) {
            $t->dropColumn(['anthropic_balance_usd', 'anthropic_balance_at']);
        });
    }
};
