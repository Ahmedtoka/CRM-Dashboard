<?php

use App\Bot\Knowledge\DefaultRules;
use App\Models\BotSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only, idempotent (spec §4.3, rulings 3–4): default instant-answer rules
 * that read knowledge entries at runtime (never a copy of their text), and the
 * default clothing-store system prompt where the owner hasn't written one.
 *
 * The owner's rules always win: defaults are inserted below the lowest active
 * owner message/both rule, and a default is skipped when its name/knowledge
 * key (or any size-chart rule) already exists or an owner rule already covers
 * one of its keywords. Existing rows and prompts are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bot_rules') && Schema::hasColumn('bot_rules', 'knowledge_key') && Schema::hasColumn('bot_rules', 'sends_size_chart')) {
            $now = now();
            $owner = DefaultRules::ownerRules();

            foreach (DefaultRules::RULES as $i => $rule) {
                $exists = DB::table('bot_rules')
                    ->where('name', $rule['name'])
                    ->when($rule['knowledge_key'] !== null, fn ($q) => $q->orWhere('knowledge_key', $rule['knowledge_key']))
                    ->when($rule['sends_size_chart'], fn ($q) => $q->orWhere('sends_size_chart', true))
                    ->exists();

                if ($exists || DefaultRules::overlaps($rule['keywords'], $owner)) {
                    continue;
                }

                DB::table('bot_rules')->insert([
                    'name' => $rule['name'],
                    'is_active' => true,
                    'priority' => DefaultRules::priorityFor($i, $owner),
                    'scope' => 'message',
                    'platforms' => '[]',
                    'match_type' => 'any_keyword',
                    'keywords' => json_encode($rule['keywords'], JSON_UNESCAPED_UNICODE),
                    'public_replies' => '[]',
                    'private_reply' => null,
                    'action' => 'reply',
                    'hits' => 0,
                    'knowledge_key' => $rule['knowledge_key'],
                    'sends_size_chart' => $rule['sends_size_chart'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('bot_settings') && Schema::hasColumn('bot_settings', 'system_prompt')) {
            DB::table('bot_settings')
                ->where(fn ($q) => $q->whereNull('system_prompt')->orWhere('system_prompt', ''))
                ->update(['system_prompt' => BotSetting::DEFAULT_SYSTEM_PROMPT]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bot_rules') && Schema::hasColumn('bot_rules', 'knowledge_key') && Schema::hasColumn('bot_rules', 'sends_size_chart')) {
            // Only rows this seed created and the owner never edited.
            DefaultRules::seededRows()->whereColumn('updated_at', 'created_at')->delete();
        }
    }
};
