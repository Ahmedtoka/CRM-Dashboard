<?php

use App\Cases\Returns\ReturnScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (round 2026-10-24, x4): the customer-facing texts of the return/exchange lifecycle
 * are inserted as `script.*` knowledge entries so the owner can reword or switch any of them off from
 * Settings → معرفة البوت. A key that already exists is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();
        $sort = (int) DB::table('bot_knowledge_entries')->max('sort');

        foreach (ReturnScripts::all() as $key => $script) {
            if (DB::table('bot_knowledge_entries')->where('key', 'script.'.$key)->exists()) {
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
