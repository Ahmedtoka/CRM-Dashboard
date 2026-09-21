<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The brand is written «Le Voile» everywhere, in Arabic texts too (owner, 2026-09-21).
 * Left as «لوفوال», the English side transliterated it afresh on every reply
 * («Lofoual», «Lufoual», «Lofoal»), so the name the customer read kept changing.
 *
 * Idempotent: it only rewrites rows that still carry the Arabic spelling, and it
 * drops the English translations built from them so they are made again from the
 * corrected source.
 */
return new class extends Migration
{
    private const ARABIC = 'لوفوال';

    private const LATIN = 'Le Voile';

    public function up(): void
    {
        if (Schema::hasTable('bot_knowledge_entries')) {
            foreach (DB::table('bot_knowledge_entries')->where('body', 'like', '%'.self::ARABIC.'%')->orWhere('title', 'like', '%'.self::ARABIC.'%')->get() as $row) {
                DB::table('bot_knowledge_entries')->where('id', $row->id)->update([
                    'title' => str_replace(self::ARABIC, self::LATIN, (string) $row->title),
                    'body' => str_replace(self::ARABIC, self::LATIN, (string) $row->body),
                    'updated_at' => now(),
                ]);
            }
        }

        // Cached English built from the old spelling, plus any wrong transliteration.
        if (Schema::hasTable('bot_translations')) {
            DB::table('bot_translations')
                ->where('source_text', 'like', '%'.self::ARABIC.'%')
                ->orWhere('text', 'like', '%Lofoual%')
                ->orWhere('text', 'like', '%Lufoual%')
                ->orWhere('text', 'like', '%Lofoal%')
                ->orWhere('text', 'like', '%Lofoual%')
                ->delete();
        }
    }

    public function down(): void
    {
        // Kept: the Latin spelling is the correct one.
    }
};
