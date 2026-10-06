<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A publication made for a launch; the Run that took it live; archived (local only) on return / reject / expire. */
    public function up(): void
    {
        if (! Schema::hasTable('ad_publications')) {
            return;
        }
        Schema::table('ad_publications', function (Blueprint $table) {
            if (! Schema::hasColumn('ad_publications', 'ad_launch_id')) {
                $table->foreignId('ad_launch_id')->nullable()->constrained('ad_launches')->nullOnDelete();
            }
            if (! Schema::hasColumn('ad_publications', 'run_write_action_id')) {
                $table->foreignId('run_write_action_id')->nullable()->constrained('ad_write_actions')->nullOnDelete();
            }
            if (! Schema::hasColumn('ad_publications', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ad_publications')) {
            return;
        }
        Schema::table('ad_publications', function (Blueprint $table) {
            if (Schema::hasColumn('ad_publications', 'ad_launch_id')) {
                $table->dropConstrainedForeignId('ad_launch_id');
            }
            if (Schema::hasColumn('ad_publications', 'run_write_action_id')) {
                $table->dropConstrainedForeignId('run_write_action_id');
            }
            if (Schema::hasColumn('ad_publications', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
    }
};
