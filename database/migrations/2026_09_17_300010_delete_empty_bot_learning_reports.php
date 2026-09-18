<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug fix (2026-09-17): a 4000-token output cap truncated the Claude learning
 * response, so `bot:learn` saved a report with an empty summary and zero
 * suggestions (the "تشغيل التعلم الآن" button on live report id 1). `LearnCommand`
 * no longer persists such a report; this cleans up any row that already
 * exists. Idempotent and guarded — safe to run again, and a no-op once the
 * bad rows are gone or on a database that never had them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_learning_reports') || ! Schema::hasTable('bot_suggestions')) {
            return;
        }

        $emptyReportIds = DB::table('bot_learning_reports')
            ->where(function ($query) {
                $query->whereNull('summary')->orWhere('summary', '');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('bot_suggestions')
                    ->whereColumn('bot_suggestions.report_id', 'bot_learning_reports.id');
            })
            ->pluck('id');

        if ($emptyReportIds->isEmpty()) {
            return;
        }

        // Explicit even though the FK already cascades on delete, so this
        // stays correct if that constraint is ever missing or disabled.
        DB::table('bot_suggestions')->whereIn('report_id', $emptyReportIds)->delete();
        DB::table('bot_learning_reports')->whereIn('id', $emptyReportIds)->delete();
    }

    public function down(): void
    {
        // Deleting bad data is not reversible.
    }
};
