<?php

use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-09-30 §2: the closing message a manual close («خلصت») sends. Insert-only and
 * idempotent: a row that exists (the owner may have reworded it) is left alone.
 */
return new class extends Migration
{
    private const KEYS = ['queue_closed_thanks'];

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
        // Kept: the owner may have reworded it.
    }
};
