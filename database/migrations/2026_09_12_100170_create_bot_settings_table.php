<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->boolean('ai_enabled')->default(true);
            $table->string('ai_classifier_model')->nullable();
            $table->string('ai_reply_model')->nullable();
            $table->text('system_prompt')->nullable();
            $table->decimal('min_confidence', 3, 2)->default(0.60);
            $table->unsignedInteger('max_bot_turns')->default(6);
            $table->json('handover_keywords')->nullable();
            $table->unsignedInteger('comment_reply_delay_min')->default(5);
            $table->unsignedInteger('comment_reply_delay_max')->default(30);
            $table->json('working_hours')->nullable();
            $table->string('outside_hours_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
