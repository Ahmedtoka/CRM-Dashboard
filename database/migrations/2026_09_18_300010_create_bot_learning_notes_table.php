<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning v2 §2: one row per reviewed conversation "episode". `notes` is the
 * short list the review wrote (possibly empty — the row still marks the
 * messages up to `last_message_id` as reviewed and carries the call's cost).
 * The nightly `bot:learn` turns a day's rows into one report and stamps them
 * with `used_in_report_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bot_learning_notes')) {
            return;
        }

        Schema::create('bot_learning_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('channel_account_id')->nullable()->constrained('channel_accounts')->nullOnDelete();
            $table->unsignedBigInteger('last_message_id');
            $table->json('notes');
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->foreignId('used_in_report_id')->nullable()->constrained('bot_learning_reports')->nullOnDelete();
            $table->timestamps();

            // The dedupe check (a review covering the conversation's newest message).
            $table->index(['conversation_id', 'last_message_id']);
            // The nightly read and the page's "today" chips.
            $table->index(['created_at', 'used_in_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_learning_notes');
    }
};
