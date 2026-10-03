<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_material_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('ad_materials', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->json('types')->nullable();
            $table->string('status', 20)->default('not_started');
            $table->json('website_links')->nullable();
            $table->json('drive_links')->nullable();
            $table->json('ig_links')->nullable();
            $table->text('content_notes')->nullable();
            $table->foreignId('media_buyer_id')->nullable()->constrained('media_buyers')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('need_stop_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('ad_material_collection', function (Blueprint $table) {
            $table->foreignId('ad_material_id')->constrained('ad_materials')->cascadeOnDelete();
            $table->foreignId('ad_material_collection_id')->constrained('ad_material_collections')->cascadeOnDelete();
            $table->primary(['ad_material_id', 'ad_material_collection_id'], 'ad_material_collection_pk');
        });

        Schema::create('ad_material_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_material_id')->constrained('ad_materials')->cascadeOnDelete();
            $table->string('disk', 30)->default('public');
            $table->string('path', 1000);
            $table->string('thumb_path', 1000)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('ad_material_ads', function (Blueprint $table) {
            $table->foreignId('ad_material_id')->constrained('ad_materials')->cascadeOnDelete();
            $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();
            $table->primary(['ad_material_id', 'ad_id']);
        });
    }

    public function down(): void
    {
        foreach (['ad_material_ads', 'ad_material_files', 'ad_material_collection', 'ad_materials', 'ad_material_collections'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
