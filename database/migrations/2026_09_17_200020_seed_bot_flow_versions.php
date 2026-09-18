<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (Task 1, flow designer): every `bot_flows` row
 * that has no version yet gets a `published` version 1 with its current
 * definition, so the version history is complete from day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_flows') || ! Schema::hasTable('bot_flow_versions')) {
            return;
        }

        $now = now();

        $flows = DB::table('bot_flows')->get(['id', 'definition']);

        foreach ($flows as $flow) {
            if (DB::table('bot_flow_versions')->where('bot_flow_id', $flow->id)->exists()) {
                continue;
            }

            DB::table('bot_flow_versions')->insert([
                'bot_flow_id' => $flow->id,
                'version' => 1,
                'status' => 'published',
                'definition' => $flow->definition,
                'note' => null,
                'created_by_id' => null,
                'published_by_id' => null,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data-only migration: nothing to reverse.
    }
};
