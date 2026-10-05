<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status sweep (A1c): when each ad and campaign was last listed by the platform, the campaign effective status, and
 * on ads_sync_runs the moment a run completed a sweep (the GONE rule compares with the previous swept run).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'ads' => ['last_seen_at'],
        'ad_campaigns' => ['effective_status', 'last_seen_at'],
        'ads_sync_runs' => ['swept_at'],
    ];

    public function up(): void
    {
        foreach (array_keys(self::COLUMNS) as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }
        Schema::table('ads', function (Blueprint $table) {
            if (! Schema::hasColumn('ads', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable();
            }
        });
        Schema::table('ad_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('ad_campaigns', 'effective_status')) {
                $table->string('effective_status', 40)->nullable()->after('status');
            }
            if (! Schema::hasColumn('ad_campaigns', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable();
            }
        });
        Schema::table('ads_sync_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('ads_sync_runs', 'swept_at')) {
                $table->timestamp('swept_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
