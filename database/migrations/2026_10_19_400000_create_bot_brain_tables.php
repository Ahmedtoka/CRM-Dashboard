<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «عقل البوت» (b2, 2026-10-19).
 *
 * bot_reply_traces: why one bot turn answered the way it did (router, layer, planner tasks, tools,
 * guards, model calls, handover), one row per bot run, with the ids of the bot messages it sent.
 * Lives exactly as long as its bot run (cascade): retention, erasure and «امسح المحادثة» remove it.
 *
 * bot_reply_feedback: a staff member's «رد البوت ده غلط» on one bot message — reasons, an optional
 * correct reply, and what the admin turned it into (a pending learning suggestion or an eval case).
 * Gone with its message.
 *
 * bot_eval_cases: eval scenarios approved from feedback; `php artisan bot:eval` runs them after the
 * file's scenarios. Kept by FreshStart (owner-curated, like the knowledge entries).
 *
 * Both conversation tables are wiped by FreshStart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_reply_traces', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bot_run_id')->constrained('bot_runs')->cascadeOnDelete();
            $t->foreignId('conversation_id')->nullable()->constrained('conversations')->cascadeOnDelete();
            $t->json('message_ids')->nullable();
            $t->json('trace');
            $t->timestamps();
            $t->index(['conversation_id', 'id']);
            $t->index('created_at');
        });

        Schema::create('bot_reply_feedback', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $t->foreignId('bot_run_id')->nullable()->constrained('bot_runs')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('reasons');
            $t->text('note')->nullable();
            $t->text('correct_reply')->nullable();
            $t->string('tier', 16)->nullable();
            $t->string('layer', 16)->nullable();
            $t->string('status', 16)->default('open');
            $t->string('converted_to', 16)->nullable();
            $t->unsignedBigInteger('suggestion_id')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['message_id', 'user_id']);
            $t->index(['status', 'created_at']);
        });

        Schema::create('bot_eval_cases', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('feedback_id')->nullable();
            $t->string('category', 40)->default('feedback');
            $t->json('messages');
            $t->json('expect');
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_eval_cases');
        Schema::dropIfExists('bot_reply_feedback');
        Schema::dropIfExists('bot_reply_traces');
    }
};
