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
    private const MENU_WORDS = ['القائمه', 'القايمه', 'القائمه الرئيسيه', 'القايمه الرئيسيه', 'الرئيسيه', 'منيو', 'المنيو', 'menu'];

    private const YES_WORDS = ['ايوه', 'ايوا', 'اه', 'نعم', 'اكيد', 'ياريت', 'يا ريت', 'تمام', 'ماشي', 'حاضر', 'yes', 'ok', 'okay'];

    private const NO_WORDS = ['لا', 'لاء', 'لا شكرا', 'مش عايزه', 'مش عاوزه', 'no'];

    private const EDIT_WORDS = ['اعدل', 'تعديل', 'عدل', 'غلط', 'اغير'];

    private const CONFIRM_WORDS = ['تمام', 'سجل', 'مظبوط', 'مضبوط', 'صح', 'اه', 'ايوه', 'تم', 'ok', 'yes'];

    /** Needles this short (normalized characters) match as whole words only. */
    private const SHORT_NEEDLE = 4;

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
