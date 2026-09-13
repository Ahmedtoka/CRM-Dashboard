<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->text('trigger_message')->nullable();
            $table->string('engine', 20);
            $table->foreignId('rule_id')->nullable()->constrained('bot_rules')->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('intent', 20)->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('decision', 30);
            $table->text('reply_text')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_runs');
    }
};
