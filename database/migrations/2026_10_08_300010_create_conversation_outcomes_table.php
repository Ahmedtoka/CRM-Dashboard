<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control room S3 (D13): one outcome per conversation episode, next to the queue's close reason
 * (which stays as it is). Additive; nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_outcomes')) {
            return;
        }

        Schema::create('conversation_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('episode_key', 40);
            $table->unsignedBigInteger('first_message_id')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->foreignId('queue_entry_id')->nullable()->constrained('queue_entries')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('outcome', 16);
            $table->string('note', 200)->nullable();
            $table->string('source', 8);
            $table->foreignId('set_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('set_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('ended_by', 16)->nullable();
            $table->boolean('reached_agent')->default(false);
            $table->timestamps();

            $table->unique(['conversation_id', 'episode_key'], 'conv_outcomes_episode_unique');
            $table->index(['conversation_id', 'ended_at'], 'conv_outcomes_conv_ended_idx');
            $table->index(['outcome', 'ended_at'], 'conv_outcomes_outcome_ended_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_outcomes');
    }
};
