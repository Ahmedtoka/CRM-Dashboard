<?php

namespace App\Bot\Flows\Returns;

use App\Bot\ArabicNormalizer;

/**
 * Reads which listed items a typed reply picks (spec 2026-09-19 §2):
 * numbers ("1 و 3", "1،3", "١ و ٣"), number words ("الاولى والتالتة"),
 * "الكل" / "كله" / "الاتنين", or a title fragment matched against the items.
 * Numbers are 1-based positions in the list the customer saw.
 */
final class ItemSelection
{
    public const ALL = 'all';

    /** Normalized whole-reply (or contained) words that pick every item. */
    private const ALL_WORDS = ['الكل', 'كله', 'كلها', 'كلهم', 'كل القطع', 'كل الحاجات', 'الجميع', 'all'];

    /** "Both" — every item only when the list has exactly two. */
    private const BOTH_WORDS = ['الاتنين', 'الاثنين', 'الاتنين دول', 'both'];

    /** Normalized number words and ordinals → value (ة is normalized to ه, أ/إ to ا). */
    private const NUMBER_WORDS = [
        'واحد' => 1, 'واحده' => 1, 'الاول' => 1, 'الاولي' => 1, 'اول' => 1, 'اولي' => 1,
        'اتنين' => 2, 'اثنين' => 2, 'التاني' => 2, 'التانيه' => 2, 'الثاني' => 2, 'الثانيه' => 2,
        'تلاته' => 3, 'ثلاثه' => 3, 'تلات' => 3, 'التالت' => 3, 'التالته' => 3, 'الثالث' => 3, 'الثالثه' => 3,
        'اربعه' => 4, 'اربع' => 4, 'الرابع' => 4, 'الرابعه' => 4,
        'خمسه' => 5, 'خمس' => 5, 'الخامس' => 5, 'الخامسه' => 5,
        'سته' => 6, 'ست' => 6, 'السادس' => 6, 'السادسه' => 6,
        'سبعه' => 7, 'سبع' => 7, 'السابع' => 7, 'السابعه' => 7,
        'تمانيه' => 8, 'ثمانيه' => 8, 'تمن' => 8, 'التامن' => 8, 'التامنه' => 8, 'الثامن' => 8,
        'تسعه' => 9, 'تسع' => 9, 'التاسع' => 9, 'التاسعه' => 9,
        'عشره' => 10, 'عشر' => 10, 'العاشر' => 10, 'العاشره' => 10,
    ];

    /** Words that say nothing about which item (never used for a title match). */
    private const STOP_WORDS = [
        'عايزه', 'عاوزه', 'عايز', 'عاوز', 'ارجع', 'ارجاع', 'استرجاع', 'ارجعها', 'ارجعه', 'ابدل', 'ابدلها', 'استبدال', 'تبديل',
        'القطعه', 'قطعه', 'القطع', 'دي', 'ده', 'دول', 'اللي', 'من', 'في', 'لو', 'سمحت', 'بس', 'كمان', 'رقم', 'المنتج', 'منتج',
        'الاوردر', 'اوردر', 'لون', 'مقاس', 'و', 'يا', 'انا', 'هي', 'هو', 'على', 'علي', 'مع',
    ];

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /**
     * @return self::ALL|list<int>|null every item, the 1-based positions picked, or null (nothing understood)
     */
    public function positions(string $text, int $count): string|array|null
    {
        $clean = $this->clean($text);

        if ($clean === '' || $count < 1) {
            return null;
        }

        foreach (self::ALL_WORDS as $w) {
            if ($clean === $w || preg_match('/(?<![\p{L}\p{N}])(?:و|ب)?'.preg_quote($w, '/').'(?![\p{L}\p{N}])/u', $clean) === 1) {
                return self::ALL;
            }
        }

        if ($count === 2 && in_array($clean, array_map(fn ($w) => $this->clean($w), self::BOTH_WORDS), true)) {
            return self::ALL;
        }

        $numbers = $this->numbers($clean);
        $inRange = array_values(array_unique(array_filter($numbers, fn (int $n) => $n >= 1 && $n <= $count)));

        return $inRange === [] ? null : $inRange;
    }

    /** A quantity 1..$max in the reply ("2", "٢", "اتنين", "الاتنين"/"كلهم" = all of them), or null. */
    public function quantity(string $text, int $max): ?int
    {
        $clean = $this->clean($text);

        if ($clean === '') {
            return null;
        }

        foreach ([...self::ALL_WORDS, 'كلهم'] as $w) {
            if (str_contains($clean, $w)) {
                return $max;
            }
        }

        if ($max === 2 && in_array($clean, array_map(fn ($w) => $this->clean($w), self::BOTH_WORDS), true)) {
            return 2;
        }

        foreach ($this->numbers($clean) as $n) {
            if ($n >= 1 && $n <= $max) {
                return $n;
            }
        }

        return null;
    }

    /**
     * The one item whose title (and variant) best matches the words she typed; null when none or a tie.
     *
     * @param  array<int, string>  $titles  item key → "title variant"
     */
    public function matchTitle(string $text, array $titles): ?int
    {
        $words = $this->words($text);

        if ($words === []) {
            return null;
        }

        $clean = $this->clean($text);
        $scores = [];

        foreach ($titles as $key => $title) {
            $titleClean = $this->clean($title);
            $titleWords = $this->words($title);
            $score = 0;

            foreach ($words as $w) {
                foreach ($titleWords as $tw) {
                    if ($w === $tw || (mb_strlen($w) >= 4 && (str_contains($tw, $w) || str_contains($w, $tw)) && mb_strlen($tw) >= 4)) {
                        $score++;

                        break;
                    }
                }
            }

            // The whole title typed out wins over a word or two in common.
            if ($titleClean !== '' && str_contains($clean, $titleClean)) {
                $score += 10;
            }

            $scores[$key] = $score;
        }

        arsort($scores);
        $best = array_key_first($scores);
        $top = $scores[$best] ?? 0;

        if ($top < 1 || count(array_filter($scores, fn (int $s) => $s === $top)) > 1) {
            return null;
        }

        return $best;
    }

    /** @return list<int> digits and number words in reading order */
    private function numbers(string $clean): array
    {
        $found = [];

        foreach (preg_split('/\s+/u', $clean) ?: [] as $token) {
            if (preg_match_all('/\d+/', $token, $m)) {
                foreach ($m[0] as $digits) {
                    $found[] = (int) $digits;
                }

                continue;
            }

            $word = self::NUMBER_WORDS[$token] ?? (str_starts_with($token, 'و') ? (self::NUMBER_WORDS[mb_substr($token, 1)] ?? null) : null);

            if ($word !== null) {
                $found[] = $word;
            }
        }

        return $found;
    }

    /** @return list<string> meaningful words without the article, 3+ letters, no stop words */
    private function words(string $text): array
    {
        $out = [];

        foreach (preg_split('/\s+/u', $this->clean($text)) ?: [] as $w) {
            if ($w === '' || in_array($w, self::STOP_WORDS, true) || preg_match('/^\d+$/', $w)) {
                continue;
            }

            $w = preg_replace('/^(?:وال|بال|ال|و)(?=\p{L}{3,})/u', '', $w) ?? $w;

            if (mb_strlen($w) >= 3 && ! in_array($w, self::STOP_WORDS, true)) {
                $out[] = $w;
            }
        }

        return array_values(array_unique($out));
    }

    /** Normalized, digits Latin, punctuation (incl. the Arabic comma) replaced by spaces, digits split from letters. */
    private function clean(string $text): string
    {
        $text = $this->normalizer->normalize($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = (string) preg_replace('/(\d)(\p{L})|(\p{L})(\d)/u', '$1$3 $2$4', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
