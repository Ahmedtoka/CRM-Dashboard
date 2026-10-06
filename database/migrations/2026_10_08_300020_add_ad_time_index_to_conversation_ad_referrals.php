<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control room S3: the chat funnel (ChatFunnel::touches) reads referrals by ad and time.
 * Guarded so it is safe to re-run on MariaDB 10.4; down() drops only this index.
 */
return new class extends Migration
{
    private const NAME = 'conv_ad_referrals_ad_time_idx';

    public function up(): void
    {
        if (! Schema::hasTable('conversation_ad_referrals')
            || Schema::hasIndex('conversation_ad_referrals', self::NAME)
            || Schema::hasIndex('conversation_ad_referrals', ['ad_external_id', 'referred_at'])) {
            return;
        }

        Schema::table('conversation_ad_referrals', fn (Blueprint $t) => $t->index(['ad_external_id', 'referred_at'], self::NAME));
    }

    public function down(): void
    {
        if (Schema::hasTable('conversation_ad_referrals') && Schema::hasIndex('conversation_ad_referrals', self::NAME)) {
            Schema::table('conversation_ad_referrals', fn (Blueprint $t) => $t->dropIndex(self::NAME));
        }
    }
};
