<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot hybrid shadow mode (bot-hybrid.md §6.1): what the planner would have done with a live turn.
 * Its own table, never bot_runs: a shadow plan is not a bot turn (turn limit, metrics, learning).
 * Wiped by FreshStart with the conversations, and gone with its conversation (her text is in it:
 * deleting the chat, erasure and the retention prune remove it too).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_shadow_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->nullable()->constrained('conversations')->cascadeOnDelete();
            $t->text('trigger_message')->nullable();
            $t->string('model')->nullable();
            $t->string('tier', 16);
            $t->string('reason', 40)->nullable();
            $t->string('kinds', 190)->nullable();
            $t->unsignedTinyInteger('directed')->default(0);
            $t->decimal('confidence', 3, 2)->nullable();
            $t->json('plan')->nullable();
            $t->decimal('cost_usd', 12, 6)->default(0);
            $t->unsignedInteger('planner_ms')->default(0);
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_shadow_plans');
    }
};
