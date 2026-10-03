<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-09-30 §3: the rating after the final close, until Part 2's `chat_reviews` exists.
 * The columns (all nullable):
 *  - `review_requested_at`: when the question went out; the once-a-day rule and the 24-hour
 *    answer window count from it;
 *  - `review_message_id`: the question's message, so a typed answer counts only while the
 *    question is the last thing we said. No foreign key; the message may be deleted with its
 *    conversation.
 *  - `review_stars` (1–5) and `reviewed_at`: her answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_entries', 'review_requested_at')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->timestamp('review_requested_at')->nullable()->after('handle_seconds');
                $t->unsignedBigInteger('review_message_id')->nullable()->after('review_requested_at');
                $t->unsignedTinyInteger('review_stars')->nullable()->after('review_message_id');
                $t->timestamp('reviewed_at')->nullable()->after('review_stars');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('queue_entries', 'review_requested_at')) {
            Schema::table('queue_entries', function (Blueprint $t) {
                $t->dropColumn(['review_requested_at', 'review_message_id', 'review_stars', 'reviewed_at']);
            });
        }
    }
};
