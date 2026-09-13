<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('inventory_quantity')->default(0);
            $table->string('image_url')->nullable();
            $table->timestamps();

            $table->unique('shopify_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
