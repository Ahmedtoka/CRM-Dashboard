<?php

use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (bot round 4, 2026-10-24): the new bot script is inserted so the owner can
 * reword or switch it off from Settings → معرفة البوت. A key that already exists is never touched.
 */
return new class extends Migration
{
    private const NEW_KEYS = ['episode_relates_ask'];

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
