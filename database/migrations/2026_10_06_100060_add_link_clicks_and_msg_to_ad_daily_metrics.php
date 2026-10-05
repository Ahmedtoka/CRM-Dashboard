<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_daily_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('ad_daily_metrics', 'link_clicks')) {
                $table->unsignedBigInteger('link_clicks')->default(0)->after('clicks');
            }
            if (! Schema::hasColumn('ad_daily_metrics', 'msg_conversations')) {
                $table->unsignedInteger('msg_conversations')->default(0)->after('link_clicks');
            }
        });
    }

    public function down(): void
    {
        foreach (['msg_conversations', 'link_clicks'] as $column) {
            if (Schema::hasColumn('ad_daily_metrics', $column)) {
                Schema::table('ad_daily_metrics', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
