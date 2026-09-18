<?php

use App\Bot\Flow\Scripts\LeVoileScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent: inserts the Le Voile intent catalog and the
 * owner's `script.*` knowledge entries. A row whose key already exists is
 * never touched, so owner edits survive re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_intents') || ! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();

        foreach (LeVoileScripts::intents() as $i => $row) {
            if (DB::table('bot_intents')->where('key', $row['key'])->exists()) {
                continue;
            }

            DB::table('bot_intents')->insert(array_merge($row, [
                'script_keys' => json_encode($row['script_keys'], JSON_UNESCAPED_UNICODE),
                'required_details' => json_encode($row['required_details'], JSON_UNESCAPED_UNICODE),
                'keywords' => json_encode($row['keywords'], JSON_UNESCAPED_UNICODE),
                'sort' => $row['sort'] ?? ($i + 1) * 10,
                'is_active' => $row['is_active'] ?? true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $sort = 1000;

        foreach (LeVoileScripts::scripts() as $key => $s) {
            $sort += 10;
            $k = "script.$key";

            if (DB::table('bot_knowledge_entries')->where('key', $k)->exists()) {
                continue;
            }

            DB::table('bot_knowledge_entries')->insert([
                'key' => $k,
                'title' => $s['title'],
                'body' => $s['body'],
                'is_active' => $s['active'],
                'is_template' => false,
                'sort' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data seed: rolled back together with the bot_intents table; script
        // entries may carry owner edits, so they are left in place.
    }
};
