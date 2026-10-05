<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ads_sync_runs')) {
            return;
        }
        foreach (['run_key', 'batch_key'] as $column) {
            if (! Schema::hasColumn('ads_sync_runs', $column)) {
                Schema::table('ads_sync_runs', fn (Blueprint $t) => $t->string($column, 36)->nullable()->index());
            }
        }
    }

    public function down(): void
    {
        foreach (['run_key', 'batch_key'] as $column) {
            if (Schema::hasColumn('ads_sync_runs', $column)) {
                Schema::table('ads_sync_runs', function (Blueprint $t) use ($column) {
                    $t->dropIndex([$column]);
                    $t->dropColumn($column);
                });
            }
        }
    }
};
