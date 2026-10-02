<?php

use App\Support\Emoji;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Addendum C4 (2026-10-01): the rating question and its thanks get new defaults — numbers 1–5,
 * no stars, no emoji. Both rows are already seeded, and the insert-only seeds never change a row,
 * so this rewrites a row only while its body still is the old default. The comparison is in PHP,
 * after `Emoji::strip` on both sides (MariaDB's collation ignores emoji, and 100010 may or may not
 * have stripped the row yet). A text the owner reworded is left alone. Idempotent; `down()` puts
 * the old defaults back only on rows still equal to the new ones.
 */
return new class extends Migration
{
    /** key => [old default (emoji stripped), new default] */
    private const TEXTS = [
        'script.queue_review_ask' => ['قيّمي خدمة {name} من 1 لـ5', 'ممكن تقيّمي خدمتنا من 1 لـ 5؟ (5 = ممتازة)'],
        'script.queue_review_thanks' => ['شكراً لتقييمك', 'شكراً على تقييمك، رأيك بيفرق معانا.'],
    ];

    public function up(): void
    {
        $this->swap(0, 1);
    }

    public function down(): void
    {
        $this->swap(1, 0);
    }

    private function swap(int $from, int $to): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        foreach (self::TEXTS as $key => $texts) {
            $rows = DB::table('bot_knowledge_entries')->where('key', $key)->get(['id', 'body']);

            foreach ($rows as $row) {
                if (trim(Emoji::strip((string) $row->body)) === trim(Emoji::strip($texts[$from]))) {
                    DB::table('bot_knowledge_entries')->where('id', $row->id)->update(['body' => $texts[$to], 'updated_at' => now()]);
                }
            }
        }
    }
};
