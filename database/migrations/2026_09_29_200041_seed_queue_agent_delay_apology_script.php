<?php

use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flow revision §4.2: the apology the customer gets once per waiting period when the moderator
 * has not replied within `agent_apology_seconds`. Insert-only and idempotent.
 */
return new class extends Migration
{
    private const KEYS = ['queue_agent_delay_apology'];

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
