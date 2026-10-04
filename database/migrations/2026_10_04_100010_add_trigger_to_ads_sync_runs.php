<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The sync page shows why each run happened (schedule, manual, backfill, setup) and who clicked. */
    public function up(): void
    {
        if (! Schema::hasColumn('ads_sync_runs', 'trigger')) {
            Schema::table('ads_sync_runs', fn (Blueprint $table) => $table->string('trigger', 20)->nullable()->after('kind'));
        }
        if (! Schema::hasColumn('ads_sync_runs', 'triggered_by_id')) {
            Schema::table('ads_sync_runs', fn (Blueprint $table) => $table->foreignId('triggered_by_id')->nullable()->after('trigger')->constrained('users')->nullOnDelete());
        }
        if (! Schema::hasIndex('ads_sync_runs', 'ads_sync_runs_started_at_index')) {
            Schema::table('ads_sync_runs', fn (Blueprint $table) => $table->index('started_at', 'ads_sync_runs_started_at_index'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('ads_sync_runs', 'ads_sync_runs_started_at_index')) {
            Schema::table('ads_sync_runs', fn (Blueprint $table) => $table->dropIndex('ads_sync_runs_started_at_index'));
        }
        if (Schema::hasColumn('ads_sync_runs', 'triggered_by_id')) {
            Schema::table('ads_sync_runs', fn (Blueprint $table) => $table->dropConstrainedForeignId('triggered_by_id'));
        }
        if (Schema::hasColumn('ads_sync_runs', 'trigger')) {
            Schema::table('ads_sync_runs', fn (Blueprint $table) => $table->dropColumn('trigger'));
        }
    }
};
