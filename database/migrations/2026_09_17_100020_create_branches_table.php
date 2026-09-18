<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches')) {
            return;
        }

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('governorate', 60);
            $t->string('area_key', 40)->index();
            $t->string('area_ar', 60);
            $t->string('area_en', 60)->nullable();
            $t->string('name', 120);
            $t->string('address', 500);
            $t->string('phone', 30)->nullable();
            $t->string('map_url', 500)->nullable();
            $t->string('hours', 200)->nullable();
            $t->json('aliases')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
