<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bot_flows')) {
            return;
        }

        Schema::create('bot_flows', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('title_ar');
            $table->boolean('is_active')->default(true);
            $table->json('definition');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_flows');
    }
};
