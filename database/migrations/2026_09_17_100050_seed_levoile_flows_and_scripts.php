<?php

use App\Bot\Flows\FlowDefinitions;
use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (Task 3): seeds the 7 Le Voile guided flows into
 * `bot_flows` and their closing/knowledge scripts into `bot_knowledge_entries`
 * (key `script.<key>`). Rows are inserted only when their key is missing —
 * an owner edit to an existing row is never overwritten, and re-running this
 * migration never duplicates rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_flows') || ! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();

        foreach (FlowDefinitions::all() as $key => $flow) {
            if (DB::table('bot_flows')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('bot_flows')->insert([
                'key' => $key,
                'title_ar' => $flow['title_ar'],
                'is_active' => true,
                'definition' => json_encode($flow['definition'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $sort = (int) DB::table('bot_knowledge_entries')->max('sort');

        foreach (FlowScripts::all() as $key => $script) {
            $entryKey = 'script.'.$key;

            if (DB::table('bot_knowledge_entries')->where('key', $entryKey)->exists()) {
                continue;
            }

            $sort += 10;
            DB::table('bot_knowledge_entries')->insert([
                'key' => $entryKey,
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
        // Data seed: left in place (the owner may have edited it).
    }
};
