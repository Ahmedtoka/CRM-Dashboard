<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow revision §2 (2026-09-29): a rostered moderator still not logged in
 * `not_arrived_alert_minutes` after the shift opened (or after she was added) is reported to
 * the leader once per shift (`shift_members.not_arrived_alerted_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_settings', 'not_arrived_alert_minutes')) {
            Schema::table('queue_settings', function (Blueprint $t) {
                $t->unsignedSmallInteger('not_arrived_alert_minutes')->default(10)->after('eta_default_handle_seconds');
            });
        }

        if (! Schema::hasColumn('shift_members', 'not_arrived_alerted_at')) {
            Schema::table('shift_members', function (Blueprint $t) {
                $t->timestamp('not_arrived_alerted_at')->nullable()->after('last_heartbeat_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shift_members', 'not_arrived_alerted_at')) {
            Schema::table('shift_members', function (Blueprint $t) {
                $t->dropColumn('not_arrived_alerted_at');
            });
        }

        if (Schema::hasColumn('queue_settings', 'not_arrived_alert_minutes')) {
            Schema::table('queue_settings', function (Blueprint $t) {
                $t->dropColumn('not_arrived_alert_minutes');
            });
        }
    }
};
