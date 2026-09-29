<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow revision §3: a waiting customer who writes gets her ticket and who is ahead, at most once per
 * `waiting_update_seconds` per entry (`position_update_sent_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_entries', 'position_update_sent_at')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->timestamp('position_update_sent_at')->nullable()->after('silence_warned_at');
            });
        }

        if (! Schema::hasColumn('queue_settings', 'waiting_update_seconds')) {
            Schema::table('queue_settings', function (Blueprint $t) {
                $t->unsignedInteger('waiting_update_seconds')->default(120)->after('silence_close_seconds');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('queue_entries', 'position_update_sent_at')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->dropColumn('position_update_sent_at');
            });
        }

        if (Schema::hasColumn('queue_settings', 'waiting_update_seconds')) {
            Schema::table('queue_settings', function (Blueprint $t) {
                $t->dropColumn('waiting_update_seconds');
            });
        }
    }
};
