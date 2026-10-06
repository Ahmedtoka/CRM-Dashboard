<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who retired a material and why (done_at stays the retired timestamp). */
    public function up(): void
    {
        if (! Schema::hasTable('ad_materials') || Schema::hasColumn('ad_materials', 'retired_by_id')) {
            return;
        }
        Schema::table('ad_materials', function (Blueprint $table) {
            $table->foreignId('retired_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('retire_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ad_materials') || ! Schema::hasColumn('ad_materials', 'retired_by_id')) {
            return;
        }
        Schema::table('ad_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retired_by_id');
            $table->dropColumn('retire_reason');
        });
    }
};
