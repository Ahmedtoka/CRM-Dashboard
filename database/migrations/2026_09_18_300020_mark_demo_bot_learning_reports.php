<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Learning v2 §1: every report written before this migration was built from
 * the demo conversations (the fake-driver accounts), so it is kept but marked
 * `stats.demo = true` and the page labels it "من بيانات تجريبية".
 *
 * Runs once in the migration sequence, before any real-only report exists.
 * Idempotent: a report already marked is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_learning_reports')) {
            return;
        }

        foreach (DB::table('bot_learning_reports')->orderBy('id')->get(['id', 'stats']) as $row) {
            $stats = is_string($row->stats) ? json_decode($row->stats, true) : null;
            $stats = is_array($stats) ? $stats : [];

            if (($stats['demo'] ?? null) === true) {
                continue;
            }

            $stats['demo'] = true;

            DB::table('bot_learning_reports')->where('id', $row->id)
                ->update(['stats' => json_encode($stats, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        // The label is harmless and the demo origin is a fact; nothing to undo.
    }
};
