<?php

namespace App\Bot;

/**
 * Normalizes Egyptian Arabic (and mixed Latin/digit) text so rule and AI
 * keyword matching is resilient to diacritics, elongation and common
 * spelling variants (see spec §5.7 step 3).
 */
class ArabicNormalizer
{
    /**
     * Arabic-Indic (٠-٩) and Persian (۰-۹) digits mapped to their Latin
     * equivalents.
     */
    private const DIGIT_MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function normalize(string $text): string
    {
        // Strip Arabic diacritics (tashkeel/harakat).
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text) ?? $text;

        // Strip tatweel (kashida elongation).
        $text = str_replace("\u{0640}", '', $text);

        // Unify hamza-on-alif variants (أ إ آ) to a plain alif.
        $text = preg_replace('/[\x{0622}\x{0623}\x{0625}]/u', 'ا', $text) ?? $text;

        // Alif maksura (ى) -> ya (ي).
        $text = str_replace("\u{0649}", "\u{064A}", $text);

        // Ta marbuta (ة) -> ha (ه).
        $text = str_replace("\u{0629}", "\u{0647}", $text);

        // Arabic-Indic/Persian digits -> Latin digits. This runs *before*
        // the repeated-letter collapse below: those digits live in the same
        // Unicode block as Arabic letters, so a price like "٩٩٩" would
        // otherwise be collapsed to a single "٩" (=> "9") instead of "999".
        $text = $this->digitsToLatin($text);

        // Collapse repeated (elongated) Arabic letters, e.g. متاحةةة.
        $text = preg_replace('/([\x{0600}-\x{06FF}])\1+/u', '$1', $text) ?? $text;

        // Lowercase (affects Latin letters only).
        $text = mb_strtolower($text, 'UTF-8');

        return $text;
    }

    /**
     * Converts Arabic-Indic and Persian digits to Latin digits, leaving
     * everything else untouched. Exposed separately from `normalize()` so
     * callers that only care about numeric values (e.g. PriceGuard) can
     * convert digits without the rest of the normalization (in particular
     * without the repeated-letter collapse, which must never see a
     * not-yet-converted digit run).
     */
    public function digitsToLatin(string $text): string
    {
        return strtr($text, self::DIGIT_MAP);
    }
}
