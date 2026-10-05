<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First day the inbox attribution history of an account is complete (A4): the ad referral was first stored on
 * 2026-09-25, and the ads history never starts before crm.ads.history_start, so existing rows get the later of the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ad_accounts', 'chat_complete_from')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->date('chat_complete_from')->nullable();
            });
        }

        $floor = (string) config('crm.ads.chat_complete_from_floor', '2026-09-25');
        $start = (string) config('crm.ads.history_start', '2026-09-01');
        DB::table('ad_accounts')->whereNull('chat_complete_from')->update(['chat_complete_from' => max($floor, $start)]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('ad_accounts', 'chat_complete_from')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->dropColumn('chat_complete_from');
            });
        }
    }
};
