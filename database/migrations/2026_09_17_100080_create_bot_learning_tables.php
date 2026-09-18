<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily learning (design §6, Task 9): one report per Cairo day plus the
 * suggestions it proposed, each pending until the owner approves it on
 * /settings/bot-learning. Nothing here is applied automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_learning_reports')) {
            Schema::create('bot_learning_reports', function (Blueprint $table) {
                $table->id();
                $table->date('report_date')->unique();
                $table->text('summary')->nullable();
                $table->json('stats')->nullable();
                $table->string('model', 80)->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bot_suggestions')) {
            Schema::create('bot_suggestions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('report_id')->constrained('bot_learning_reports')->cascadeOnDelete();
                $table->string('type', 30);
                $table->string('target', 120)->nullable();
                $table->json('current')->nullable();
                $table->json('proposed');
                $table->text('reason')->nullable();
                $table->json('evidence')->nullable();
                $table->string('status', 12)->default('pending');
                $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->string('error')->nullable();
                $table->timestamps();

                // The command's duplicate check: a pending suggestion with the same type+target.
                $table->index(['status', 'type', 'target']);
            });
        }

        if (Schema::hasTable('bot_settings') && ! Schema::hasColumn('bot_settings', 'ai_learning_model')) {
            Schema::table('bot_settings', function (Blueprint $table) {
                $table->string('ai_learning_model')->nullable()->after('ai_reply_model');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_suggestions');
        Schema::dropIfExists('bot_learning_reports');

        if (Schema::hasTable('bot_settings') && Schema::hasColumn('bot_settings', 'ai_learning_model')) {
            Schema::table('bot_settings', function (Blueprint $table) {
                $table->dropColumn('ai_learning_model');
            });
        }
    }
};
