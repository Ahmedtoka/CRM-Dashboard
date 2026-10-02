<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-09-30 §1: when the customer last sent only an acknowledgement (thanks, emoji, a
 * sticker) in her open window. The customer-silence clock ignores it, so a window the moderator
 * forgets to close still warns and closes on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_entries', 'last_ack_at')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->timestamp('last_ack_at')->nullable()->after('last_customer_message_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('queue_entries', 'last_ack_at')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->dropColumn('last_ack_at');
            });
        }
    }
};
