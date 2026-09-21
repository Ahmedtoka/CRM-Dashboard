<?php

use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sentences around the product picture cards (owner, 2026-09-22), and «نرجع لـالموديلات»
 * read as one word: the flow label now sits in «». Insert-only and idempotent; a text the
 * owner already reworded is left alone.
 */
return new class extends Migration
{
    private const KEYS = ['products_intro', 'products_type_intro'];

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

        DB::table('bot_knowledge_entries')->where('key', 'script.flow_back_to')->where('body', 'نرجع لـ{flow_label} 🌸')
            ->update(['body' => FlowScripts::all()['flow_back_to']['body'], 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Kept: the owner may have reworded them.
    }
};
