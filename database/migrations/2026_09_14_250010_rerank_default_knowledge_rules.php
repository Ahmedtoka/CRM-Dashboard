<?php

use App\Bot\Knowledge\DefaultRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up for databases where the first seed already ran with high
 * priorities (60–64) and outranked the owner's rules. For each seeded default
 * the owner hasn't edited (updated_at = created_at): deactivate it when an
 * active owner message/both rule overlaps its keywords, otherwise move it
 * below the lowest owner rule. Timestamps are left alone (not an owner edit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_rules') || ! Schema::hasColumn('bot_rules', 'knowledge_key') || ! Schema::hasColumn('bot_rules', 'sends_size_chart')) {
            return;
        }

        $owner = DefaultRules::ownerRules();

        foreach (DefaultRules::seededRows()->whereColumn('updated_at', 'created_at')->get(['id', 'name', 'keywords']) as $row) {
            $index = DefaultRules::indexOf((string) $row->name);

            if ($index === null) {
                continue;
            }

            DefaultRules::overlaps(DefaultRules::decode($row->keywords), $owner)
                ? DB::table('bot_rules')->where('id', $row->id)->update(['is_active' => false])
                : DB::table('bot_rules')->where('id', $row->id)->update(['priority' => DefaultRules::priorityFor($index, $owner)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_rules') || ! Schema::hasColumn('bot_rules', 'knowledge_key') || ! Schema::hasColumn('bot_rules', 'sends_size_chart')) {
            return;
        }

        foreach (DefaultRules::seededRows()->whereColumn('updated_at', 'created_at')->get(['id', 'name']) as $row) {
            $index = DefaultRules::indexOf((string) $row->name);

            if ($index !== null) {
                DB::table('bot_rules')->where('id', $row->id)->update(['is_active' => true, 'priority' => 60 + $index]);
            }
        }
    }
};
