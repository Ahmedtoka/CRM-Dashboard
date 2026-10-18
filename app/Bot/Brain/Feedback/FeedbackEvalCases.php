<?php

namespace App\Bot\Brain\Feedback;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The eval scenarios approved from staff feedback (bot_eval_cases), in the YAML scenario shape, so
 * `php artisan bot:eval` checks them after the file's own. Ids start at 10000 to never meet the file's.
 */
final class FeedbackEvalCases
{
    public const ID_OFFSET = 10000;

    /** @return list<array<string, mixed>> */
    public static function scenarios(): array
    {
        if (! Schema::hasTable('bot_eval_cases')) {
            return [];
        }

        return DB::table('bot_eval_cases')->where('is_active', true)->orderBy('id')->get()
            ->map(fn ($row) => [
                'id' => self::ID_OFFSET + (int) $row->id,
                'category' => (string) $row->category,
                'safety' => false,
                'messages' => (array) json_decode((string) $row->messages, true),
                'expect' => (array) json_decode((string) $row->expect, true),
            ])->all();
    }
}
