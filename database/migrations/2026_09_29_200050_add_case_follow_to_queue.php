<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow revision §6: a customer with an open support case carries it on her ticket
 * (`open_case_id`), and with `case_follow_owner` on she is preferred for the moderator who
 * opened it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_entries', 'open_case_id')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->foreignId('open_case_id')->nullable()->after('support_case_id')->constrained('support_cases')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('queue_settings', 'case_follow_owner')) {
            Schema::table('queue_settings', function (Blueprint $t) {
                $t->boolean('case_follow_owner')->default(true)->after('night_message_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('queue_entries', 'open_case_id')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->dropConstrainedForeignId('open_case_id');
            });
        }

        if (Schema::hasColumn('queue_settings', 'case_follow_owner')) {
            Schema::table('queue_settings', function (Blueprint $t) {
                $t->dropColumn('case_follow_owner');
            });
        }
    }
};
