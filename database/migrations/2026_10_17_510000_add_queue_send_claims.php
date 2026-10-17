<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close-cycle hotfixes (2026-10-17, C1 / H-04): a queue message is sent at most once.
 *
 * - `queue_message_sends`: one row per `SendQueueMessage` job (its `send_key`, kept across the
 *   job's retries), written in the transaction that creates the Message — a retry after the
 *   Message exists finds the row and sends nothing.
 * - `queue_entries.closing_claimed_at`: the closing message («سعدنا بخدمتك») of this close was
 *   claimed; never sent twice for one close. A closed reply window or an empty text is decided
 *   before the claim, so it never claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('queue_message_sends')) {
            Schema::create('queue_message_sends', function (Blueprint $t) {
                $t->id();
                $t->uuid('send_key')->unique();
                $t->unsignedBigInteger('queue_entry_id')->index();
                $t->string('script_key', 60);
                $t->timestamp('created_at')->nullable()->index();
            });
        }

        Schema::table('queue_entries', function (Blueprint $t) {
            if (! Schema::hasColumn('queue_entries', 'closing_claimed_at')) {
                $t->timestamp('closing_claimed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $t) {
            if (Schema::hasColumn('queue_entries', 'closing_claimed_at')) {
                $t->dropColumn('closing_claimed_at');
            }
        });

        Schema::dropIfExists('queue_message_sends');
    }
};
