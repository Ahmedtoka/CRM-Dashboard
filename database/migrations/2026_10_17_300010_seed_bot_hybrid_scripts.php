<?php

use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (bot hybrid, 2026-10-17): the new bot scripts are inserted so the
 * owner can reword or switch off any of them from Settings → معرفة البوت. A key that already
 * exists is never touched.
 */
return new class extends Migration
{
    private const NEW_KEYS = ['hold_short', 'tasks_next_offer', 'tasks_later', 'tasks_summary_title', 'tasks_too_many', 'tasks_already_recorded', 'bot_sorry_handover', 'bot_safe_fallback'];

    public function up(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();
        $scripts = FlowScripts::all();
        $sort = (int) DB::table('bot_knowledge_entries')->max('sort');

        foreach (self::NEW_KEYS as $key) {
            $script = $scripts[$key] ?? null;

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
        // Data: left in place (the owner may have edited it since).
    }
};
