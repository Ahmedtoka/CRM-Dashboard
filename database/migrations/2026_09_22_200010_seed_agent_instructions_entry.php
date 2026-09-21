<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's own instructions to the store agent (2026-09-22): one knowledge entry, edited from
 * «كل ردود البوت», added to the agent's prompt as it is. Insert-only and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries') || DB::table('bot_knowledge_entries')->where('key', 'agent_instructions')->exists()) {
            return;
        }

        DB::table('bot_knowledge_entries')->insert([
            'key' => 'agent_instructions',
            'title' => 'تعليمات الـ Agent',
            'body' => "خليكي مختصرة ولطيفة وردّي على قد السؤال.\nلو العميلة عايزة تطلب، وجّهيها تطلب من الموقع.\nمتوعديش بخصم أو ميعاد أو استثناء مش مكتوب في المعلومات.",
            'is_active' => true,
            'is_template' => false,
            'sort' => (int) DB::table('bot_knowledge_entries')->max('sort') + 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Kept: the owner may have reworded it.
    }
};
