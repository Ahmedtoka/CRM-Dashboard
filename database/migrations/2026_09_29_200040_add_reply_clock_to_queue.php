<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow revision §4: the moderator-reply clock. `awaiting_reply_since` is when the customer
 * started waiting for the assignee (delivery, or her message after the assignee had answered
 * everything); `apology_sent_at` and `overdue_alerted_at` make the apology and the leader alert
 * once per waiting period; `excluded_user_id` is the moderator a handed-off customer is never
 * given back to. The three timers default to 180 / 300 / 480 seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_entries', 'awaiting_reply_since')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->timestamp('awaiting_reply_since')->nullable()->after('last_agent_message_at');
                $t->timestamp('apology_sent_at')->nullable()->after('awaiting_reply_since');
                $t->timestamp('overdue_alerted_at')->nullable()->after('apology_sent_at');
                $t->foreignId('excluded_user_id')->nullable()->after('reserved_user_id')->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('queue_settings', 'agent_apology_seconds')) {
            Schema::table('queue_settings', function (Blueprint $t) {
                $t->unsignedInteger('agent_apology_seconds')->default(180)->after('silence_close_seconds');
                $t->unsignedInteger('agent_reassign_first_seconds')->default(300)->after('agent_apology_seconds');
                $t->unsignedInteger('agent_reassign_seconds')->default(480)->after('agent_reassign_first_seconds');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('queue_entries', 'awaiting_reply_since')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->dropConstrainedForeignId('excluded_user_id');
                $t->dropColumn(['awaiting_reply_since', 'apology_sent_at', 'overdue_alerted_at']);
            });
        }

        if (Schema::hasColumn('queue_settings', 'agent_apology_seconds')) {
            Schema::table('queue_settings', function (Blueprint $t) {
                $t->dropColumn(['agent_apology_seconds', 'agent_reassign_first_seconds', 'agent_reassign_seconds']);
            });
        }
    }
};
