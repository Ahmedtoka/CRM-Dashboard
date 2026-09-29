<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Final review of the flow revision: the counts reach the customer worded (App\Queue\QueueWording),
 * so «{ahead}» is a whole phrase («إنتي أول واحدة في الدور» / «قدامك عميلتين» …) and
 * «{eta_minutes}» / «{minutes}» carry their unit («دقيقتين» / «5 دقايق» / «15 دقيقة») — never
 * «وقدامك 0» or «حوالي 1 دقايق». The texts around them lose their own «قدامك» / «دقيقة» / «دقايق».
 *
 * A stored row is rewritten only while its body is byte-identical to the previous default (compared
 * in PHP: the utf8mb4_unicode_ci collation would call an edited emoji, diacritic or trailing space
 * "the same"), updated by id, so the owner's own edits are kept; `down()` restores the previous
 * default under the same test. Both texts are fixed here, never read from FlowScripts.
 */
return new class extends Migration
{
    /** key => [previous default, new default]. */
    private const TEXTS = [
        'queue_enqueued' => [
            'تمام ✅ هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #{ticket}، وقدامك {ahead} وحوالي {eta_minutes} دقيقة 🌸',
            'تمام ✅ هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #{ticket}، و{ahead}، وهنكون معاكي خلال حوالي {eta_minutes} 🌸',
        ],
        'queue_returning' => [
            'أهلاً بيكي تاني 🌸 بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #{ticket} وقدامك حوالي {eta_minutes} دقيقة.',
            'أهلاً بيكي تاني 🌸 بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #{ticket} وهنكون معاكي خلال حوالي {eta_minutes}.',
        ],
        'queue_position_update' => [
            'لسه معاكي 💛 رقم تذكرتك #{ticket}، وقدامك {ahead} {eta_sentence}',
            'لسه معاكي 💛 رقم تذكرتك #{ticket}، و{ahead} {eta_sentence}',
        ],
        'queue_eta_sentence' => [
            'وهنكون معاكي خلال حوالي {minutes} دقايق',
            'وهنكون معاكي خلال حوالي {minutes}',
        ],
        'queue_silence_warning' => [
            'لسه معانا يا فندم؟ 🌸 المحادثة هتتقفل تلقائي بعد {minutes} دقيقة لو مفيش رد، وتقدري تكتبيلنا في أي وقت وهنرجّعك بأولوية.',
            'لسه معانا يا فندم؟ 🌸 المحادثة هتتقفل تلقائي بعد {minutes} لو مفيش رد، وتقدري تكتبيلنا في أي وقت وهنرجّعك بأولوية.',
        ],
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
            foreach (DB::table('bot_knowledge_entries')->where('key', 'script.'.$key)->get(['id', 'body']) as $row) {
                if ($row->body === $texts[$from]) {
                    DB::table('bot_knowledge_entries')->where('id', $row->id)->update(['body' => $texts[$to], 'updated_at' => now()]);
                }
            }
        }
    }
};
