<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->string('scope', 20)->default('both');
            $table->json('platforms')->nullable();
            $table->string('match_type', 20)->default('any_keyword');
            $table->json('keywords')->nullable();
            $table->json('public_replies')->nullable();
            $table->text('private_reply')->nullable();
            $table->string('action', 30)->default('reply');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_rules');
    }
};
