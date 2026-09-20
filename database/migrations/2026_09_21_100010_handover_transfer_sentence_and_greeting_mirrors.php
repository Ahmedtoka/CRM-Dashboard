<?php

use App\Bot\Flows\FlowScripts;
use App\Bot\Flows\GreetingMirror;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (2026-09-21, the owner's two bot fixes).
 *
 * 1. Every handover now says out loud that she is being transferred, so the three
 *    working-hours scripts are reworded — only while the row still carries the body
 *    seeded on 2026-09-19. An owner edit is left exactly as she wrote it.
 * 2. The greeting mirrors («وعليكم السلام ورحمة الله 🌸», «صباح النور»…) are inserted
 *    as their own scripts so she can reword or switch off any of them from the
 *    dashboard; a key that already exists is never touched.
 *
 * `script.handover_ack` stays in the catalogue (the owner may still use it); it is
 * simply no longer the transfer promise.
 */
return new class extends Migration
{
    /** key => the body seeded on 2026-09-19; only this exact text is replaced. */
    private const PREVIOUS_BODIES = [
        'handover_in_hours' => 'تمام ✅ حولتك لحد من الفريق، هيرد عليكي خلال دقايق 🌸',
        'handover_after_hours' => 'تمام ✅ سجلت طلبك، وفريق خدمة العملاء هيرد عليكي أول ما نفتح {next_opening} 🌸',
        'handover_no_hours' => 'تمام ✅ حولتك لحد من الفريق، هيرد عليكي في أقرب وقت 🌸',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();
        $scripts = FlowScripts::all();

        foreach (self::PREVIOUS_BODIES as $key => $previous) {
            $body = (string) ($scripts[$key]['body'] ?? '');

            if ($body === '' || $body === $previous) {
                continue;
            }

            DB::table('bot_knowledge_entries')
                ->where('key', 'script.'.$key)
                ->where('body', $previous)
                ->update(['body' => $body, 'updated_at' => $now]);
        }

        $sort = (int) DB::table('bot_knowledge_entries')->max('sort');

        foreach (GreetingMirror::scripts() as $key => $script) {
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
        // Data fix: left in place (the owner may have edited it since).
    }
};
