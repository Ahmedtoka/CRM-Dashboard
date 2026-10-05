<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per platform mutation of an ad_write_actions row (write-api 2.2): before/after, attempts, outcome. */
    public function up(): void
    {
        if (Schema::hasTable('ad_write_steps')) {
            return;
        }

        Schema::create('ad_write_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_write_action_id')->constrained('ad_write_actions')->cascadeOnDelete();
            $table->unsignedTinyInteger('seq');
            $table->string('op', 30);
            $table->string('level', 10);
            $table->string('target_external_id', 100);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('state', 20);
            $table->timestamp('request_sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('meta')->nullable();
            $table->string('platform_trace', 100)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['ad_write_action_id', 'seq'], 'ad_write_steps_action_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_write_steps');
    }
};
