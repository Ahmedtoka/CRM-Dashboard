<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bilingual bot (design 2026-09-21 §6.4): «can I talk to a person please» has to reach the
 * team the same way «عايزة اكلم حد» does. The `human_request` intent's keywords are what
 * both the router and the mid-flow check read, so the English phrasings are added to them.
 *
 * Data-only and idempotent: phrasings the row already has are not repeated, and nothing the
 * owner added is removed.
 */
return new class extends Migration
{
    private const ENGLISH = [
        'talk to a person',
        'talk to someone',
        'speak to someone',
        'talk to an agent',
        'speak to an agent',
        'talk to a human',
        'real person',
        'human agent',
        'a representative',
        'agent please',
        'support team',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bot_intents')) {
            return;
        }

        $row = DB::table('bot_intents')->where('key', 'human_request')->first();

        if ($row === null) {
            return;
        }

        $keywords = json_decode((string) $row->keywords, true);
        $keywords = is_array($keywords) ? array_map('strval', $keywords) : [];
        $merged = array_values(array_unique([...$keywords, ...self::ENGLISH]));

        if (count($merged) === count($keywords)) {
            return;
        }

        DB::table('bot_intents')->where('id', $row->id)
            ->update(['keywords' => json_encode($merged, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Data fix: left in place (the owner may have edited the list since).
    }
};
