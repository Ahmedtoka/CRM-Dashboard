<?php

use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The handover queue's customer-facing texts (Task 3, 2026-09-29 design spec).
 * Insert-only and idempotent: a key the owner already edited, or deleted on
 * purpose, is left alone.
 */
return new class extends Migration
{
    private const KEYS = [
        'queue_enqueued', 'queue_night', 'queue_left_5', 'queue_left_3', 'queue_left_1',
        'queue_apology', 'queue_called', 'queue_auto_closed', 'queue_returning', 'queue_reassigned',
        'queue_review_ask', 'queue_review_thanks', 'queue_case_opened', 'queue_case_resolved',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();
        $sort = (int) DB::table('bot_knowledge_entries')->max('sort');

        foreach (self::KEYS as $key) {
            $script = FlowScripts::all()[$key] ?? null;

            if ($script === null || DB::table('bot_knowledge_entries')->where('key', 'script.'.$key)->exists()) {
                continue;
            }

            $sort += 10;
            DB::table('bot_knowledge_entries')->insert([
                'key' => 'script.'.$key,
                'title' => $script['title'],
                'body' => $script['body'],
                'is_active' => true,
                'is_template' => false,
                'sort' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Kept: the owner may have reworded them.
    }
};
