<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bot hybrid P0 (bot-hybrid.md §7): where a turn's time and money went — the tier that answered,
 * planner / model / tool milliseconds, model rounds, cache reads and writes, and how long she
 * waited for the burst. cost_usd keeps 6 decimals (a Haiku 5.5 planner call is ~$0.0002).
 * Also B1 (owner, 2026-10-17): the burst wait goes 8 → 3 s and its max 25 → 12 s on rows that
 * still hold the old defaults; an owner-tuned value is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_runs', function (Blueprint $t) {
            $t->string('tier', 16)->nullable()->after('decision');
            $t->unsignedInteger('burst_wait_ms')->nullable();
            $t->unsignedInteger('planner_ms')->nullable();
            $t->unsignedInteger('llm_ms')->nullable();
            $t->unsignedInteger('tools_ms')->nullable();
            $t->unsignedSmallInteger('rounds')->nullable();
            $t->unsignedInteger('cache_read_tokens')->nullable();
            $t->unsignedInteger('cache_write_tokens')->nullable();
        });

        Schema::table('bot_runs', fn (Blueprint $t) => $t->decimal('cost_usd', 12, 6)->default(0)->change());

        if (Schema::hasTable('bot_settings')) {
            DB::table('bot_settings')->where('burst_wait_seconds', 8)->update(['burst_wait_seconds' => 3]);
            DB::table('bot_settings')->where('burst_max_wait_seconds', 25)->update(['burst_max_wait_seconds' => 12]);
        }
    }

    public function down(): void
    {
        Schema::table('bot_runs', fn (Blueprint $t) => $t->dropColumn(['tier', 'burst_wait_ms', 'planner_ms', 'llm_ms', 'tools_ms', 'rounds', 'cache_read_tokens', 'cache_write_tokens']));
        Schema::table('bot_runs', fn (Blueprint $t) => $t->decimal('cost_usd', 8, 4)->default(0)->change());
    }
};
