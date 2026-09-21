<?php

namespace App\Bot\Language;

/**
 * Protects everything that must survive a translation untouched (design 2026-09-21 §2)
 * and, in the same move, makes the translation cache useful.
 *
 * Links, e-mail addresses, `{placeholders}`, numbers (prices, order numbers, dates,
 * phone numbers) and emoji are replaced by `⟦0⟧`, `⟦1⟧`… before the text is hashed and
 * sent to the model, and put back afterwards. So «الأوردر #1234 — 1,200 ج.م 🌸» and
 * «الأوردر #9876 — 950 ج.م 🌸» are one cached source, «الأوردر #⟦0⟧ — ⟦1⟧ ج.م ⟦2⟧», and
 * no order number, price, link or emoji can ever come back changed.
 *
 * `⟦` / `⟧` (U+27E6/U+27E7) are used as the marker because they appear in no text the
 * owner writes and no model rewrites them.
 */
final class TranslationMask
{
    private const OPEN = '⟦';

    private const CLOSE = '⟧';

    /**
     * One pass, alternation in priority order: links, e-mail, `{placeholder}`, `%s`,
     * «a name the bot is quoting» (a product, a branch, a piece she picked — never
     * translated), emoji, then numbers. It has to be a single pass: a second pass would
     * see the digit inside a marker it just wrote and mask the marker itself.
     */
    private const PATTERN = '~https?://\S+|www\.[^\s،,]+'
        .'|[\w.+-]+@[\w-]+\.[\w.]+'
        .'|\{[a-z_][a-z0-9_]*\}'
        .'|%[sd]'
        .'|«[^»]*»'
        .'|[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2300}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{20E3}]+'
        // A leading # or + is left in place on purpose: «#{order_number}» and «#1047» must
        // mask to the same «#⟦n⟧», or the seeded translation of the template never matches.
        // The ⟦ guards keep the digits of a marker already written from being masked again.
        .'|(?<![\p{L}\d\x{27E6}])[\d\x{0660}-\x{0669}]+(?:[.,:/-][\d\x{0660}-\x{0669}]+)*(?!\x{27E7})~iu';

    /**
     * @return array{0:string, 1:list<string>} the masked text and the values, in marker order
     */
    public function mask(string $text): array
    {
        $values = [];
        $mark = function (string $value) use (&$values): string {
            $values[] = $value;

            return self::OPEN.(count($values) - 1).self::CLOSE;
        };

        // The names and words this turn registered come first: they may contain digits,
        // links or emoji of their own, and they must be masked whole.
        foreach (KeptNames::all() as $value => $_) {
            if (str_contains($text, $value)) {
                $text = str_replace($value, $mark($value), $text);
            }
        }

        return [(string) preg_replace_callback(self::PATTERN, fn (array $m) => $mark($m[0]), $text), $values];
    }

    /**
     * Puts the masked values back. A marker the model dropped is simply not restored;
     * a marker it duplicated is restored with the same value. Outside Arabic the digits
     * come back in Latin («٤» → «4», design §1) and a word registered with a ready
     * translation comes back translated (KeptNames::swap).
     *
     * @param  list<string>  $values
     */
    public function restore(string $text, array $values, string $locale = LanguageDetector::AR): string
    {
        $swaps = KeptNames::all();

        return (string) preg_replace_callback(
            '~'.self::OPEN.'(\d+)'.self::CLOSE.'~u',
            function (array $m) use ($values, $locale, $swaps) {
                $value = $values[(int) $m[1]] ?? '';

                if (isset($swaps[$value][$locale])) {
                    return $swaps[$value][$locale];
                }

                return $locale === LanguageDetector::AR ? $value : self::latinDigits($value);
            },
            $text,
        );
    }

    /** Arabic-Indic digits as Latin ones; everything else untouched. */
    public static function latinDigits(string $text): string
    {
        return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    /** Every marker of the masked source is still in $translated (nothing was eaten). */
    public function keepsMarkers(string $masked, string $translated): bool
    {
        preg_match_all('~'.self::OPEN.'(\d+)'.self::CLOSE.'~u', $masked, $source);
        preg_match_all('~'.self::OPEN.'(\d+)'.self::CLOSE.'~u', $translated, $out);

        return array_diff(array_unique($source[1]), array_unique($out[1])) === [];
    }

    /** True when there is Arabic to translate at all (a link or a price alone is not). */
    public static function hasArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }
}
