<?php

namespace App\Bot\Flows;

use App\Bot\ArabicNormalizer;

/**
 * Deterministic answer resolution for typed replies (no AI): option
 * title/synonym containment, Egyptian mobile, name, exit words, yes/no and
 * summary confirm/edit words.
 */
final class FlowAnswerResolver
{
    /** Whole-reply words that leave the flow and show the main menu. */
    // "الغاء" is deliberately not an exit word: it is the cancel option of cancel_edit and a main-menu synonym.
    private const MENU_WORDS = ['القائمه', 'القايمه', 'القائمه الرئيسيه', 'القايمه الرئيسيه', 'الرئيسيه', 'منيو', 'المنيو', 'menu', 'main menu', 'the menu', 'home', 'main'];

    private const YES_WORDS = ['ايوه', 'ايوا', 'اه', 'نعم', 'اكيد', 'ياريت', 'يا ريت', 'تمام', 'ماشي', 'حاضر', 'yes', 'ok', 'okay', 'sure', 'yeah', 'yep', 'please', 'go ahead'];

    private const NO_WORDS = ['لا', 'لاء', 'لا شكرا', 'مش عايزه', 'مش عاوزه', 'no', 'nope', 'no thanks', 'not now'];

    private const EDIT_WORDS = ['اعدل', 'تعديل', 'عدل', 'غلط', 'اغير', 'edit', 'change', 'wrong', 'fix'];

    private const CONFIRM_WORDS = ['تمام', 'سجل', 'مظبوط', 'مضبوط', 'صح', 'اه', 'ايوه', 'تم', 'ok', 'yes', 'confirm', 'correct', 'right', 'done', 'submit'];

    /** Needles this short (normalized characters) match as whole words only. */
    private const SHORT_NEEDLE = 4;

    /** Fuzzy matching (design 2026-09-21 §3): how close, and how far ahead of the next option. */
    private const SIMILAR_ENOUGH = 0.8;

    private const CLEAR_LEAD = 0.08;

    /**
     * Fuzzy matching ignores needles shorter than this: «الغي» and «اللي» are one edit
     * apart, so short options must be matched exactly or not at all.
     */
    private const FUZZY_MIN_NEEDLE = 5;

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /** Normalized, punctuation-free, single-spaced text. */
    public function clean(string $text): string
    {
        $text = $this->normalizer->normalize($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The first option whose normalized title or synonym is contained in the text.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    public function matchOption(array $options, string $text): ?array
    {
        $clean = $this->clean($text);

        if ($clean === '') {
            return null;
        }

        foreach ($options as $o) {
            foreach ([(string) ($o['title'] ?? ''), ...array_map('strval', $o['synonyms'] ?? [])] as $needle) {
                $n = $this->clean($needle);

                if ($n !== '' && $this->containsNeedle($clean, $n)) {
                    return $o;
                }
            }
        }

        return null;
    }

    /**
     * Design 2026-09-21 §3 step 2: when nothing matched exactly, the closest option title
     * or synonym — a typo, a plural, a different word order («exchang», «track order»,
     * «المقاسات» for «المقاس»). Deliberately strict: a wrong guess is worse than a re-ask,
     * so a candidate must be at least SIMILAR_ENOUGH similar and clearly ahead of the runner-up.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    public function fuzzyOption(array $options, string $text): ?array
    {
        $clean = $this->clean($text);

        // A long sentence is a story, not a mistyped option: the model reads that one.
        if ($clean === '' || mb_strlen($clean) < 3 || count(explode(' ', $clean)) > 8) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        $secondScore = 0.0;

        foreach ($options as $o) {
            $score = 0.0;

            foreach ([(string) ($o['title'] ?? ''), ...array_map('strval', $o['synonyms'] ?? [])] as $needle) {
                $n = $this->clean($needle);

                if ($n !== '' && mb_strlen($n) >= self::FUZZY_MIN_NEEDLE) {
                    $score = max($score, $this->similarity($clean, $n));
                }
            }

            if ($score > $bestScore) {
                [$secondScore, $bestScore, $best] = [$bestScore, $score, $o];
            } elseif ($score > $secondScore) {
                $secondScore = $score;
            }
        }

        return $bestScore >= self::SIMILAR_ENOUGH && $bestScore - $secondScore >= self::CLEAR_LEAD ? $best : null;
    }

    /**
     * 0..1: the best of "the whole reply looks like it", "one of its words looks like it",
     * and "her words cover the option's words" — the last one is what reads «returns and
     * exchange» as «Returns & Exchanges», where the words are the same but the order,
     * the plural and the «&» are not.
     */
    private function similarity(string $text, string $needle): float
    {
        $best = max($this->ratio($text, $needle), $this->covers($text, $needle));

        foreach (explode(' ', $text) as $word) {
            if (mb_strlen($word) >= 3) {
                $best = max($best, $this->ratio($word, $needle));
            }
        }

        return $best;
    }

    /**
     * The share of the needle's own words (4 letters or more) that her text carries, matched
     * on their first four letters so a plural or a suffix still counts. A needle of one short
     * word scores 0: that is what exact matching is for.
     */
    private function covers(string $text, string $needle): float
    {
        $words = array_values(array_filter(explode(' ', $needle), fn (string $w) => mb_strlen($w) >= 4));

        if (count($words) < 2) {
            return 0.0;
        }

        $found = 0;

        foreach ($words as $word) {
            if (str_contains($text, mb_substr($word, 0, 4))) {
                $found++;
            }
        }

        return $found / count($words);
    }

    private function ratio(string $a, string $b): float
    {
        $length = max(mb_strlen($a), mb_strlen($b));

        if ($length === 0) {
            return 0.0;
        }

        // levenshtein() is byte-based; Arabic is multi-byte, so the characters are
        // mapped onto single bytes first (at most 255 distinct characters per pair).
        [$a, $b] = $this->toBytes($a, $b);

        return max(0.0, 1.0 - levenshtein($a, $b) / $length);
    }

    /** @return array{0:string, 1:string} */
    private function toBytes(string $a, string $b): array
    {
        $map = [];
        $encode = function (string $text) use (&$map): string {
            $out = '';

            foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                $map[$char] ??= count($map) + 1;
                $out .= chr(min(255, $map[$char]));
            }

            return $out;
        };

        return [$encode($a), $encode($b)];
    }

    /** A reply ending with "?" or "؟" is a question, never an option pick. */
    public function isQuestion(string $text): bool
    {
        return preg_match('/[?؟]\s*$/u', $text) === 1;
    }

    /**
     * Needles of up to SHORT_NEEDLE normalized characters match only as a whole word (an
     * Arabic proclitic like ال/و/ب/ف/ل may precede it), so "تاني" does not match "التانيه";
     * longer needles may match by containment.
     */
    private function containsNeedle(string $clean, string $needle): bool
    {
        if (mb_strlen($needle) > self::SHORT_NEEDLE) {
            return str_contains($clean, $needle);
        }

        return preg_match('/(?<![\p{L}\p{N}])(?:و|ف|ب|ل)?(?:ال|لل)?'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $clean) === 1;
    }

    /** An Egyptian mobile in the text, as 01XXXXXXXXX. */
    public function phone(string $text): ?string
    {
        $digits = (string) preg_replace('/[\s\-]+/u', '', $this->normalizer->digitsToLatin($text));

        return preg_match('/(?<!\d)(?:\+?2)?(01[0125]\d{8})(?!\d)/', $digits, $m) ? $m[1] : null;
    }

    /** 2-40 letters (spaces allowed), no digits. */
    public function name(string $text): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (preg_match('/\d/u', $this->normalizer->digitsToLatin($name)) || ! preg_match('/^[\p{L}\p{M} .\'\-]+$/u', $name)) {
            return null;
        }

        $letters = preg_match_all('/\p{L}/u', $name);

        return $letters >= 2 && mb_strlen($name) <= 40 ? $name : null;
    }

    /** 'menu' when the whole reply is an exit word, else null. */
    public function exitWord(string $text): ?string
    {
        $clean = $this->clean($text);

        return match (true) {
            in_array($clean, self::MENU_WORDS, true) => 'menu',
            default => null,
        };
    }

    public function mentionsMenu(string $text): bool
    {
        $clean = $this->clean($text);

        return str_contains($clean, 'القائمه') || str_contains($clean, 'القايمه') || str_contains($clean, 'منيو') || str_contains($clean, 'menu');
    }

    /** 'yes' | 'no' when the whole reply is a yes/no word. */
    public function yesNo(string $text): ?string
    {
        $clean = $this->clean($text);

        return match (true) {
            in_array($clean, self::YES_WORDS, true) => 'yes',
            in_array($clean, self::NO_WORDS, true) => 'no',
            default => null,
        };
    }

    /** 'edit' | 'confirm' for a typed reply to a summary. */
    public function summaryChoice(string $text): ?string
    {
        $words = explode(' ', $this->clean($text));

        foreach (self::EDIT_WORDS as $w) {
            if (in_array($w, $words, true)) {
                return 'edit';
            }
        }

        foreach (self::CONFIRM_WORDS as $w) {
            if (in_array($w, $words, true)) {
                return 'confirm';
            }
        }

        return null;
    }
}
