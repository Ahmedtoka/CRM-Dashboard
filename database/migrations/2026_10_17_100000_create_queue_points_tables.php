<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handover queue Part 2 (refresh 2026-10-17): the moderators' points ledger, the leader's quality
 * checks and the case SLA breach stamp. `business_date` columns are plain 'Y-m-d' strings (no
 * Eloquent date cast: sqlite and MariaDB then store the same thing). MariaDB 10.4 safe: nullable
 * times are timestamp()->nullable(), no JSON defaults, foreign keys dropped with their tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete(); // the moderator scored
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('queue_entry_id')->unique()->constrained('queue_entries')->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('business_date', 10);
            $t->unsignedTinyInteger('score');
            $t->json('checklist')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'business_date']);
        });

        Schema::create('score_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('business_date', 10); // the Cairo day of the work the line rewards
            $t->string('kind', 12); // base | speed | case | review | qa | no_reply
            $t->smallInteger('points');
            $t->smallInteger('requested'); // before the daily cap
            $t->string('reason_key', 40);
            $t->json('reason_vars')->nullable();
            $t->string('unique_key', 100)->unique();
            $t->foreignId('queue_entry_id')->nullable()->constrained('queue_entries')->nullOnDelete();
            $t->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('support_case_id')->nullable()->constrained('support_cases')->nullOnDelete();
            $t->foreignId('qa_review_id')->nullable()->constrained('qa_reviews')->nullOnDelete();
            $t->foreignId('reverses_id')->nullable()->unique()->constrained('score_events')->nullOnDelete();
            $t->foreignId('reversed_by_id')->nullable()->constrained('score_events')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'business_date']);
            $t->index(['business_date', 'kind']);
        });

        Schema::table('support_cases', function (Blueprint $t) {
            $t->timestamp('sla_breached_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_cases', fn (Blueprint $t) => $t->dropColumn('sla_breached_at'));
        Schema::dropIfExists('score_events');
        Schema::dropIfExists('qa_reviews');
    }
};
