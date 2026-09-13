<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_id')->nullable();
            $table->string('title');
            $table->string('handle')->nullable();
            $table->string('image_url')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique('shopify_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
