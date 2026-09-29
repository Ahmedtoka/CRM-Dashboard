<?php

use App\Bot\Flows\FlowScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flow revision wording: the daily ticket is named «رقم تذكرتك #…» (the owner's live test read
 * «رقمك في الدور 4» as "4th in line"), and where a place is known it says «قدامك …».
 * A stored row is rewritten only while its body still equals the previous default exactly, so
 * the owner's own edits are kept; `down()` restores the previous default under the same test.
 */
return new class extends Migration
{
    /** key => previous default body. */
    private const OLD = [
        'queue_enqueued' => 'تمام ✅ هيتم تحويلك لموظفة خدمة العملاء. رقمك في الدور {ticket} وقدامك حوالي {eta_minutes} دقيقة 🌸',
        'queue_returning' => 'أهلاً بيكي تاني 🌸 بنرجّعك لنفس الموظفة بأولوية، رقمك {ticket} وقدامك حوالي {eta_minutes} دقيقة.',
        'queue_enqueued_no_eta' => 'رقمك في الدور {ticket} 🎟️ الفريق بيبدأ دلوقتي وهنكون معاكي في أقرب وقت، خليكي معانا 🙏',
        'queue_night' => 'شكراً لرسالتك 🌸 إحنا خارج مواعيد العمل دلوقتي. رقمك في الدور {ticket} وهنكلمك أول ما نفتح الساعة {opening} بالترتيب. من فضلك ما تبعتيش رسايل تانية عشان الدور ما يتأثرش.',
    ];

    public function up(): void
    {
        $this->swap(fn (string $key) => [self::OLD[$key], FlowScripts::all()[$key]['body'] ?? null]);
    }

    public function down(): void
    {
        $this->swap(fn (string $key) => [FlowScripts::all()[$key]['body'] ?? null, self::OLD[$key]]);
    }

    /** @param  callable(string): array{0: ?string, 1: ?string}  $pair  [from, to] for a key */
    private function swap(callable $pair): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        foreach (array_keys(self::OLD) as $key) {
            [$from, $to] = $pair($key);

            if ($from === null || $to === null) {
                continue;
            }

            // Compared in PHP, byte for byte: the database collation (utf8mb4_unicode_ci) would treat
            // a changed emoji, a dropped diacritic or a trailing space as "the same" and overwrite an edit.
            foreach (DB::table('bot_knowledge_entries')->where('key', 'script.'.$key)->get(['id', 'body']) as $row) {
                if ($row->body === $from) {
                    DB::table('bot_knowledge_entries')->where('id', $row->id)->update(['body' => $to, 'updated_at' => now()]);
                }
            }
        }
    }
};
