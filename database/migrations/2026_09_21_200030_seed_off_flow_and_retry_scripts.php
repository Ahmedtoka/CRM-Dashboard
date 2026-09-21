<?php

use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (design 2026-09-21 §3 and §6).
 *
 * 1. `script.flow_retry` no longer just says «معلش مفهمتش 🙏» before repeating the same
 *    question — it now introduces the options («معلش مش واضحة ليا 🙏 اختاري من دول:»).
 *    Replaced only while the row still carries the body seeded on 2026-09-17; an owner
 *    edit is left exactly as she wrote it.
 * 2. The off-flow scripts (§6) and the second-miss script (§3) are inserted so the owner
 *    can reword or switch off any of them from Settings → معرفة البوت. A key that already
 *    exists is never touched.
 */
return new class extends Migration
{
    /** key => the body seeded on 2026-09-17; only this exact text is replaced. */
    private const PREVIOUS_BODIES = [
        'flow_retry' => 'معلش مفهمتش 🙏',
    ];

    private const NEW_KEYS = [
        'flow_not_understood',
        'flow_back_to',
        'flow_too_many_detours',
        'flow_switch_offer',
        'flow_thanks',
        'flow_resume_offer',
        'flow_stale_tap',
        'flow_menu_fallback',
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
                ->update(['body' => $body, 'title' => $scripts[$key]['title'], 'updated_at' => $now]);
        }

        $sort = (int) DB::table('bot_knowledge_entries')->max('sort');

        foreach (self::NEW_KEYS as $key) {
            $script = $scripts[$key] ?? null;

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
        // Data fix: left in place (the owner may have edited it since).
    }
};
