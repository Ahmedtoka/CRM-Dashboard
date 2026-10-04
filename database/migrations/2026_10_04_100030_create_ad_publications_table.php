<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per (file x caption) published as a paused ad; platform_media caches the upload per account on the file. */
    public function up(): void
    {
        if (! Schema::hasTable('ad_publications')) {
            Schema::create('ad_publications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ad_material_id')->constrained('ad_materials')->cascadeOnDelete();
                $table->foreignId('ad_material_file_id')->constrained('ad_material_files')->cascadeOnDelete();
                $table->foreignId('ad_account_id')->constrained('ad_accounts');
                $table->string('platform', 20);
                $table->string('campaign_external_id');
                $table->string('campaign_name', 500)->nullable();
                $table->string('adset_external_id');
                $table->string('adset_name', 500)->nullable();
                $table->json('identity')->nullable();
                $table->unsignedTinyInteger('caption_index')->default(1);
                $table->string('headline', 500);
                $table->text('primary_text');
                $table->string('cta', 30);
                $table->string('ad_name', 500);
                $table->text('link');
                $table->text('url_tags');
                $table->string('status', 20)->default('queued');
                $table->string('external_ad_id')->nullable();
                $table->text('error')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('linked_at')->nullable();
                $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['ad_material_id', 'created_at'], 'ad_publications_material_created_index');
            });
        }

        if (! Schema::hasColumn('ad_material_files', 'platform_media')) {
            Schema::table('ad_material_files', function (Blueprint $table) {
                $table->json('platform_media')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_publications');
        if (Schema::hasColumn('ad_material_files', 'platform_media')) {
            Schema::table('ad_material_files', fn (Blueprint $table) => $table->dropColumn('platform_media'));
        }
    }
};
