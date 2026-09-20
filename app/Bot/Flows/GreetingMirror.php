<?php

namespace App\Bot\Flows;

use App\Bot\ArabicNormalizer;
use App\Bot\Knowledge\KnowledgeBase;
use App\Models\BotKnowledgeEntry;

/**
 * «ترد التحية بنفس التحية» (2026-09-21): when the customer opens with a greeting the bot
 * mirrors it on its own first line, before the usual `{time_greeting}` line and the menu.
 *
 *   السلام عليكم (أي كتابة)          → وعليكم السلام ورحمة الله 🌸
 *   صباح الخير / الفل / النور        → صباح النور / صباح الفل والنور / صباح النور
 *   مساء الخير / الفل / النور        → مساء النور / مساء الفل والنور / مساء النور
 *   أهلاً / اهلين / هاي / hi / hello → أهلاً بيكي 🌸
 *
 * Nothing matches (or she did not open with a greeting) → no extra line at all.
 *
 * Every reply is an editable script (`bot_knowledge_entries`, key
 * `script.greeting_mirror_<key>`, Settings → معرفة البوت): the owner may reword any of
 * them, and a script she turns off simply stops mirroring that greeting. The matcher is
 * normalized with App\Bot\ArabicNormalizer (tashkeel, أ/إ/آ→ا, ة→ه, ى→ي, elongation) plus
 * punctuation/emoji stripping and a repeated-letter squeeze, so «اهلاااا!!! 😍» and
 * «hiii» match too.
 */
final class GreetingMirror
{
    public const SCRIPT_PREFIX = 'greeting_mirror_';

    /**
     * Mirror key => the openers that pick it, most specific first. Written the way the
     * customer types them; both sides are normalized and squeezed before comparing, and
     * the opener must be the whole message or be followed by a space.
     *
     * @var array<string, list<string>>
     */
    public const OPENERS = [
        'salam' => ['السلام عليكم', 'سلام عليكم', 'السلام', 'سلام', 'assalamu alaikum', 'salam'],
        'sabah_noor' => ['صباح النور', 'صباح نور'],
        'sabah_full' => ['صباح الفل', 'صباح فل'],
        'sabah' => ['صباح الخير', 'صباح'],
        'masa_noor' => ['مساء النور', 'مسا النور', 'مساء نور', 'مسا نور'],
        'masa_full' => ['مساء الفل', 'مسا الفل', 'مساء فل', 'مسا فل'],
        'masa' => ['مساء الخير', 'مسا الخير', 'مساء', 'مسا'],
        'hi' => ['اهلا', 'اهلين', 'هلا', 'هاي', 'هالو', 'hi', 'hello', 'hey'],
    ];

    public function __construct(
        private readonly ArabicNormalizer $normalizer,
        private readonly KnowledgeBase $knowledge,
    ) {}

    /** The mirror key her text opens with, or null when it is not a greeting. */
    public function key(string $text): ?string
    {
        $clean = $this->clean($text);

        if ($clean === '') {
            return null;
        }

        foreach (self::OPENERS as $key => $openers) {
            foreach ($openers as $opener) {
                $o = $this->clean($opener);

                if ($o !== '' && ($clean === $o || str_starts_with($clean, $o.' '))) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * The line to put first, or null when she did not greet (or the owner turned that
     * mirror off). The script body is used as written — no placeholders, no AI rewrite.
     */
    public function line(string $text): ?string
    {
        $key = $this->key($text);

        return $key === null ? null : $this->script(self::SCRIPT_PREFIX.$key);
    }

    /** The reply already opens with the mirror (never send it twice in one turn). */
    public function alreadyMirrored(string $reply, string $line): bool
    {
        return str_starts_with(ltrim($reply), $line);
    }

    /** @return array<string, array{title:string, body:string}> the seeded scripts of every mirror */
    public static function scripts(): array
    {
        $all = FlowScripts::all();

        return array_filter($all, fn (string $key) => str_starts_with($key, self::SCRIPT_PREFIX), ARRAY_FILTER_USE_KEY);
    }

    /** The active script, its seeded text when the row does not exist, null when the owner turned it off. */
    private function script(string $key): ?string
    {
        $body = trim((string) $this->knowledge->get('script.'.$key)?->body);

        if ($body !== '') {
            return $body;
        }

        if (BotKnowledgeEntry::query()->where('key', 'script.'.$key)->exists()) {
            return null;
        }

        $seeded = trim((string) (FlowScripts::all()[$key]['body'] ?? ''));

        return $seeded !== '' ? $seeded : null;
    }

    /**
     * Normalized for matching: tashkeel/elongation/أإآ/ة/ى handled by ArabicNormalizer,
     * then punctuation and emoji dropped, repeated letters squeezed («اهلاااا» → «اهلا»,
     * «hiii» → «hi»; the openers are squeezed the same way, so «hello» still matches).
     */
    private function clean(string $text): string
    {
        $text = $this->normalizer->normalize($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/(\p{L})\1+/u', '$1', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
