<?php

use App\Bot\Flow\Scripts\LeVoileScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (overnight refinement change 2, inbox analysis
 * 2026-09-14): adds the human_request, sale_offer, thanks and fabric_season
 * intents plus the script.thanks knowledge entry to a database seeded before
 * they existed. A row whose key already exists is never touched.
 */
return new class extends Migration
{
    private const NEW_INTENT_KEYS = ['human_request', 'sale_offer', 'thanks', 'fabric_season'];

    public function up(): void
    {
        if (! Schema::hasTable('bot_intents') || ! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();
        $intents = collect(LeVoileScripts::intents())->keyBy('key');
        $sort = (int) DB::table('bot_intents')->max('sort');

        foreach (self::NEW_INTENT_KEYS as $key) {
            if (DB::table('bot_intents')->where('key', $key)->exists()) {
                continue;
            }

            $row = $intents->get($key);

            if ($row === null) {
                continue;
            }

            $sort += 10;

            DB::table('bot_intents')->insert([
                'key' => $row['key'],
                'group' => $row['group'],
                'label_ar' => $row['label_ar'],
                'label_en' => $row['label_en'],
                'route' => $row['route'],
                'priority' => $row['priority'],
                'queue' => $row['queue'],
                'script_keys' => json_encode($row['script_keys'], JSON_UNESCAPED_UNICODE),
                'required_details' => json_encode($row['required_details'], JSON_UNESCAPED_UNICODE),
                'keywords' => json_encode($row['keywords'], JSON_UNESCAPED_UNICODE),
                'sort' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('bot_knowledge_entries')->where('key', 'script.thanks')->exists()) {
            $s = LeVoileScripts::scripts()['thanks'];
            $scriptSort = (int) DB::table('bot_knowledge_entries')->max('sort');

            DB::table('bot_knowledge_entries')->insert([
                'key' => 'script.thanks',
                'title' => $s['title'],
                'body' => $s['body'],
                'is_active' => $s['active'],
                'is_template' => false,
                'sort' => $scriptSort + 10,
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
