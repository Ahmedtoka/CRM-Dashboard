<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** AI (and hand-edited) ad captions per material video: three angles, replaced on regenerate. */
    public function up(): void
    {
        if (Schema::hasTable('ad_material_captions')) {
            return;
        }

        Schema::create('ad_material_captions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_material_id')->constrained('ad_materials')->cascadeOnDelete();
            $table->foreignId('ad_material_file_id')->nullable()->constrained('ad_material_files')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(1);
            $table->string('angle', 20);
            $table->string('headline', 80);
            $table->text('primary_text');
            $table->string('cta', 30);
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->foreignId('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['ad_material_id', 'ad_material_file_id'], 'ad_material_captions_material_file_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_material_captions');
    }
};
