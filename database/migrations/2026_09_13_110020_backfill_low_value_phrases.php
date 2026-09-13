<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The low-value phrase list grew from 6 to 14 defaults after launch (spec §11.1).
 * Any bot_settings row created before this migration — including rows created
 * before the column even existed, which are left at null — is missing the new
 * phrases; this merges them in without touching a moderator's own additions.
 */
return new class extends Migration
{
    private const DEFAULT_LOW_VALUE_PHRASES = [
        'شكرا', 'شكراً', 'تمام', 'اوك', 'اوكي', 'ok', 'okay', 'تسلم', 'ميرسي', '👍', '❤️', '🙏', '😍', '🌹',
    ];

    public function up(): void
    {
        DB::table('bot_settings')->select('id', 'low_value_phrases')->orderBy('id')->get()->each(function ($row) {
            $existing = $row->low_value_phrases ? (json_decode($row->low_value_phrases, true) ?: []) : [];
            $merged = array_values(array_unique(array_merge($existing, self::DEFAULT_LOW_VALUE_PHRASES)));

            DB::table('bot_settings')->where('id', $row->id)->update([
                'low_value_phrases' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function down(): void
    {
        // Data backfill only; not reversible (we cannot tell which phrases
        // were already present versus added by this migration).
    }
};
