<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bot_intents')) {
            return;
        }

        Schema::create('bot_intents', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60)->unique();
            $t->string('group', 40);
            $t->string('label_ar');
            $t->string('label_en');
            $t->string('route', 30);
            $t->string('priority', 10)->default('low');
            $t->string('queue', 10)->nullable();
            $t->json('script_keys')->nullable();
            $t->json('required_details')->nullable();
            $t->json('keywords')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_intents');
    }
};
