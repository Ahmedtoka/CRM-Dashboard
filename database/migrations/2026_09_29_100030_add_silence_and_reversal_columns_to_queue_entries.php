<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `last_agent_message_at`: the assignee's last reply in this window; the customer-silence clock
 * starts there (and only while it is later than her last message).
 * `reversed_at`: the close was reversed because she came back inside the confirm window; a
 * close is reversed at most once and a reversed close is never confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_entries', function (Blueprint $t) {
            if (! Schema::hasColumn('queue_entries', 'last_agent_message_at')) {
                $t->timestamp('last_agent_message_at')->nullable()->after('last_customer_message_at');
            }

            if (! Schema::hasColumn('queue_entries', 'reversed_at')) {
                $t->timestamp('reversed_at')->nullable()->after('confirmed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $t) {
            $t->dropColumn(['last_agent_message_at', 'reversed_at']);
        });
    }
};
