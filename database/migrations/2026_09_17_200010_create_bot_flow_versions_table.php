<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bot_flow_versions')) {
            return;
        }

        Schema::create('bot_flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_flow_id')->constrained('bot_flows')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 12)->index();
            $table->json('definition');
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['bot_flow_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_flow_versions');
    }
};
