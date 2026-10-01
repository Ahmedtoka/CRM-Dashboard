<?php

use App\Bot\Language\BotTranslator;
use App\Models\BotFlowVersion;
use App\Models\BotKnowledgeEntry;
use App\Models\BotRule;
use App\Models\BotSetting;
use App\Models\QuickReply;
use App\Models\QuickReplyCategory;
use App\Models\SupportCase;
use App\Support\Emoji;
use Illuminate\Support\Facades\DB;

/**
 * Spec 2026-10-01 §6: the strip migration cleans every stored customer-facing text, row by row in PHP.
 * The rows below sit next to whatever the earlier migrations seeded, and the whole table must come out clean.
 */
function stripEmojiMigration(): object
{
    return require base_path('database/migrations/2026_10_01_100010_strip_emoji_from_stored_texts.php');
}

/** Every text column / json leaf of a table that still carries an emoji, as "table#id.column". */
function emojiLeftIn(string $table, array $texts, array $jsons): array
{
    $left = [];
    foreach (DB::table($table)->get() as $row) {
        foreach ($texts as $col) {
            if (is_string($row->{$col}) && Emoji::contains($row->{$col})) {
                $left[] = "{$table}#{$row->id}.{$col}";
            }
        }
        foreach ($jsons as $col) {
            $decoded = is_string($row->{$col}) ? json_decode($row->{$col}, true) : null;
            if ($decoded !== null && Emoji::stripDeep($decoded) !== $decoded) {
                $left[] = "{$table}#{$row->id}.{$col}";
            }
        }
    }

    return $left;
}

it('strips emoji from every stored customer-facing text and is idempotent', function () {
    $kb = BotKnowledgeEntry::factory()->create(['title' => 'ترحيب 🌸', 'body' => "أهلاً 👋\nاختاري 👇"]);
    $default = BotKnowledgeEntry::factory()->create(['body' => 'تمام ✅ هيتم تحويلك لموظف خدمة العملاء خلال دقايق 🌸']);
    $owners = BotKnowledgeEntry::factory()->create(['body' => 'تمام ✅ هحولك لزميلتي حالاً 🌸']);
    $plain = BotKnowledgeEntry::factory()->create(['title' => 'من غير', 'body' => "  نص عادي #12 ← ٣  \n"]);
    $qr = QuickReply::factory()->create(['title' => 'شكر 🙏', 'body' => 'شكراً ليكي 🌸']);
    $category = QuickReplyCategory::factory()->create(['name' => '⭐ المهمة']);
    $rule = BotRule::factory()->create(['public_replies' => ['ردينا في الخاص 💌', 'تمام'], 'private_reply' => 'السعر 500 جنيه 🌸']);
    BotSetting::current()->update([
        'system_prompt' => 'ردي بالعامية، ردود قصيرة، وإيموجي واحد بالكتير.',
        'outside_hours_message' => 'إحنا قافلين دلوقتي 🌙 هنرد عليكي الصبح',
    ]);
    $definition = ['start' => 'ask', 'steps' => ['ask' => ['type' => 'choice', 'text' => 'تحبي إيه؟ 👇', 'options' => [['title' => '📦 أوردر', 'payload' => 'order', 'next' => 'end']]], 'end' => ['type' => 'end']]];
    $version = BotFlowVersion::factory()->create(['definition' => $definition]);
    DB::table('bot_flows')->where('id', $version->bot_flow_id)->update(['definition' => json_encode($definition, JSON_UNESCAPED_UNICODE)]);
    $intent = DB::table('bot_intents')->insertGetId(['key' => 'emoji_test', 'group' => 'test', 'label_ar' => '🛍️ شراء', 'label_en' => '🛍️ Buy', 'route' => 'script', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bot_translations')->insert([
        ['source_hash' => str_repeat('a', 64), 'source_text' => 'أهلاً 🌸', 'locale' => 'en', 'text' => 'Hello 🌸', 'origin' => 'auto', 'context' => null, 'created_at' => now(), 'updated_at' => now()],
        ['source_hash' => str_repeat('b', 64), 'source_text' => 'شكراً 🙏', 'locale' => 'en', 'text' => 'Thank you 🙏', 'origin' => 'human', 'context' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $case = SupportCase::factory()->create(['summary' => "📋 حالة #1\n\n👤 العميل\nسارة"]);
    $plainBefore = DB::table('bot_knowledge_entries')->where('id', $plain->id)->first();

    $migration = stripEmojiMigration();
    $migration->up();
    $migration->up(); // idempotent

    expect($kb->fresh()->title)->toBe('ترحيب')
        ->and($kb->fresh()->body)->toBe("أهلاً\nاختاري")
        ->and((array) DB::table('bot_knowledge_entries')->where('id', $plain->id)->first())->toBe((array) $plainBefore)
        ->and($default->fresh()->body)->toBe('تمام، هيتم تحويلك لموظف خدمة العملاء خلال دقايق')
        ->and($owners->fresh()->body)->toBe('تمام هحولك لزميلتي حالاً')
        ->and($qr->fresh()->title)->toBe('شكر')
        ->and($qr->fresh()->body)->toBe('شكراً ليكي')
        ->and($category->fresh()->name)->toBe('المهمة')
        ->and($rule->fresh()->public_replies)->toBe(['ردينا في الخاص', 'تمام'])
        ->and($rule->fresh()->private_reply)->toBe('السعر 500 جنيه')
        ->and(BotSetting::current()->fresh()->system_prompt)->toBe('ردي بالعامية، ردود قصيرة، ومن غير أي إيموجي.')
        ->and(BotSetting::current()->fresh()->outside_hours_message)->toBe('إحنا قافلين دلوقتي هنرد عليكي الصبح')
        ->and($version->fresh()->definition['steps']['ask']['text'])->toBe('تحبي إيه؟')
        ->and($version->fresh()->definition['steps']['ask']['options'][0])->toBe(['title' => 'أوردر', 'payload' => 'order', 'next' => 'end'])
        ->and(json_decode(DB::table('bot_flows')->where('id', $version->bot_flow_id)->value('definition'), true)['steps']['ask']['options'][0]['title'])->toBe('أوردر')
        ->and(DB::table('bot_intents')->where('id', $intent)->first(['label_ar', 'label_en']))->toEqual((object) ['label_ar' => 'شراء', 'label_en' => 'Buy'])
        // R12: the machine row with an emoji is dropped (it regenerates on demand); the reviewed
        // English of the reworded sources is seeded; the human row is cleaned in place.
        ->and(DB::table('bot_translations')->where('source_hash', str_repeat('a', 64))->exists())->toBeFalse()
        ->and(app(BotTranslator::class)->cached('أقدر أساعد حضرتك إزاي؟ اختاري من القائمة', 'en'))->toBe('How can I help you? Pick from the menu')
        ->and(DB::table('bot_translations')->where('origin', 'human')->count())->toBe(1)
        ->and(DB::table('bot_translations')->where('origin', 'human')->value('text'))->toBe('Thank you')
        ->and($case->fresh()->summary)->toBe("حالة #1\n\nالعميل\nسارة");

    // Every listed table is clean, the seeded rows of the earlier migrations included.
    $left = array_merge(
        emojiLeftIn('bot_knowledge_entries', ['title', 'body'], []),
        emojiLeftIn('bot_settings', ['system_prompt', 'outside_hours_message'], []),
        emojiLeftIn('bot_rules', ['private_reply'], ['public_replies']),
        emojiLeftIn('quick_replies', ['title', 'body'], []),
        emojiLeftIn('quick_reply_categories', ['name'], []),
        emojiLeftIn('bot_flows', [], ['definition']),
        emojiLeftIn('bot_flow_versions', [], ['definition']),
        emojiLeftIn('bot_intents', ['label_ar', 'label_en'], []),
        emojiLeftIn('bot_translations', ['text'], []),
        emojiLeftIn('support_cases', ['summary'], []),
    );
    expect($left)->toBe([]);
});

it('does nothing on a clean database and its down() restores nothing', function () {
    $qr = QuickReply::factory()->create(['title' => 'عادي', 'body' => 'نص من غير إيموجي']);
    $before = (array) DB::table('quick_replies')->where('id', $qr->id)->first();

    stripEmojiMigration()->up();
    stripEmojiMigration()->down();

    expect((array) DB::table('quick_replies')->where('id', $qr->id)->first())->toBe($before);
});
