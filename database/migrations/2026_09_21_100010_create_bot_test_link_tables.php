<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public test links for the team (design 2026-09-21).
 *
 * `bot_test_links` is the link the owner shares; `bot_test_sessions` is one
 * tester's run of it (a "Start over" makes a new session for the same tester,
 * numbered by `run_no`); `bot_test_session_steps` is the flow/step trail each
 * run leaves, which the report's funnel reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_test_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('label', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_sessions')->nullable();
            $table->unsignedInteger('max_messages_per_session')->default(60);
            // The dedicated `driver = test` channel account this link's sessions live on.
            $table->foreignId('channel_account_id')->nullable()->constrained('channel_accounts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('sessions_count')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });

        Schema::create('bot_test_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_test_link_id')->constrained('bot_test_links')->cascadeOnDelete();
            $table->string('session_token', 64)->unique();
            $table->string('tester_name', 60);
            // Which run this is for that tester on that link («سارة — الجلسة 2»).
            $table->unsignedInteger('run_no')->default(1);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('device_family', 40)->nullable();
            $table->string('user_agent', 255)->nullable();
            // Never the raw address: only a salted hash, for the per-IP rate limit and counts.
            $table->string('ip_hash', 64)->nullable();
            $table->unsignedInteger('messages_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 30)->nullable();
            $table->timestamps();

            $table->index(['bot_test_link_id', 'id']);
            $table->index(['bot_test_link_id', 'tester_name']);
        });

        Schema::create('bot_test_session_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_test_session_id')->constrained('bot_test_sessions')->cascadeOnDelete();
            $table->string('flow_key', 60);
            // Null marks the moment the flow ended (the trail's "left the flow" row).
            $table->string('step_id', 60)->nullable();
            $table->timestamp('entered_at');

            $table->index(['bot_test_session_id', 'id']);
            $table->index(['flow_key', 'step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_test_session_steps');
        Schema::dropIfExists('bot_test_sessions');
        Schema::dropIfExists('bot_test_links');
    }
};
