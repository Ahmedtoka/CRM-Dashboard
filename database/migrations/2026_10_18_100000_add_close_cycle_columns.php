<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Close cycle (docs/round-2026-10-17/close-cycle-FINAL.md §10).
 *
 * - `queue_entries.end_mode` (thanks / bot / silent): how a final close ended, next to the
 *   unchanged `close_reason` (H-02); `closing_skipped_reason`: why the closing message did not go
 *   out; `reopened_at`: she came back inside the window after «خلصت» (Q9, a quality metric).
 * - `queue_settings`: `reopen_after_done_minutes` (Q8), `silence_warn_customer` /
 *   `auto_close_message` (Q4, off), `human_outside_minutes` (Q12); the silence clock moves to
 *   10 minutes (Q5) only where it is still the old default.
 * - `messages.echo_app_id`: the app a Meta echo came from (H-05).
 * - `conversations.bot_paused_until`: a person answered from outside the CRM (Q12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_entries', function (Blueprint $t) {
            if (! Schema::hasColumn('queue_entries', 'end_mode')) {
                $t->string('end_mode', 10)->nullable();
                $t->string('closing_skipped_reason', 30)->nullable();
                $t->timestamp('reopened_at')->nullable();
                // RC-4 / RC-6 / RC-16.
                $t->unsignedInteger('close_seq')->default(0);
                $t->timestamp('customer_back_at')->nullable();
                $t->boolean('silent_call')->default(false);
            }
        });

        Schema::table('queue_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('queue_settings', 'reopen_after_done_minutes')) {
                $t->unsignedSmallInteger('reopen_after_done_minutes')->default(30);
                $t->boolean('silence_warn_customer')->default(false);
                $t->boolean('auto_close_message')->default(false);
                $t->unsignedSmallInteger('human_outside_minutes')->default(30);
            }
        });

        DB::table('queue_settings')->where('silence_close_seconds', 300)->update(['silence_close_seconds' => 600]);
        DB::table('queue_settings')->where('silence_warn_seconds', 180)->where('silence_close_seconds', 600)->update(['silence_warn_seconds' => 540]);

        Schema::table('messages', function (Blueprint $t) {
            if (! Schema::hasColumn('messages', 'echo_app_id')) {
                $t->string('echo_app_id', 40)->nullable();
            }
        });

        Schema::table('conversations', function (Blueprint $t) {
            if (! Schema::hasColumn('conversations', 'bot_paused_until')) {
                $t->timestamp('bot_paused_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $t) => Schema::hasColumn('conversations', 'bot_paused_until') ? $t->dropColumn('bot_paused_until') : null);
        Schema::table('messages', fn (Blueprint $t) => Schema::hasColumn('messages', 'echo_app_id') ? $t->dropColumn('echo_app_id') : null);

        DB::table('queue_settings')->where('silence_warn_seconds', 540)->where('silence_close_seconds', 600)->update(['silence_warn_seconds' => 180]);
        DB::table('queue_settings')->where('silence_close_seconds', 600)->update(['silence_close_seconds' => 300]);

        Schema::table('queue_settings', function (Blueprint $t) {
            if (Schema::hasColumn('queue_settings', 'reopen_after_done_minutes')) {
                $t->dropColumn(['reopen_after_done_minutes', 'silence_warn_customer', 'auto_close_message', 'human_outside_minutes']);
            }
        });

        Schema::table('queue_entries', function (Blueprint $t) {
            if (Schema::hasColumn('queue_entries', 'end_mode')) {
                $t->dropColumn(['end_mode', 'closing_skipped_reason', 'reopened_at', 'close_seq', 'customer_back_at', 'silent_call']);
            }
        });
    }
};
