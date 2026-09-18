<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (overnight refinement change 1): the greeting no
 * longer names no one -- it names the agent (ميار) and greets by time of day
 * via the {time_greeting} placeholder (App\Bot\Flow\ScriptPlaceholders).
 * Only while the row still carries the original seeded body; an owner edit
 * is left in place.
 */
return new class extends Migration
{
    private const OLD_BODY = 'أهلا بيكي يا فندم 🌸 مع حضرتك من لوفوال';

    private const NEW_BODY = '{time_greeting} يا فندم يومك حلو ان شاء الله 😍 مع حضرتك ميار من لوفوال';

    public function up(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        DB::table('bot_knowledge_entries')
            ->where('key', 'script.greeting')
            ->where('body', self::OLD_BODY)
            ->update(['body' => self::NEW_BODY, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Data fix: left in place (the owner may have edited it since).
    }
};
