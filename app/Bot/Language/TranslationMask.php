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

    /** In order: links, e-mail, {placeholder}, emoji, numbers (with , . : / - inside). */
    private const PATTERNS = [
        '~https?://\S+|www\.[^\s،,]+~iu',
        '~[\w.+-]+@[\w-]+\.[\w.]+~u',
        '~\{[a-z_][a-z0-9_]*\}~i',
        '~[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2300}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{20E3}]+~u',
        '~(?<![\p{L}\d])[+#]?\d+(?:[.,:/-]\d+)*~u',
    ];

    /**
     * @return array{0:string, 1:list<string>} the masked text and the values, in marker order
     */
    public function mask(string $text): array
    {
        $values = [];

        foreach (self::PATTERNS as $pattern) {
            $text = (string) preg_replace_callback($pattern, function (array $m) use (&$values) {
                $values[] = $m[0];

                return self::OPEN.(count($values) - 1).self::CLOSE;
            }, $text);
        }

        return [$text, $values];
    }

    /**
     * Puts the masked values back. A marker the model dropped is simply not restored;
     * a marker it duplicated is restored with the same value.
     *
     * @param  list<string>  $values
     */
    public function restore(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '~'.self::OPEN.'(\d+)'.self::CLOSE.'~u',
            fn (array $m) => $values[(int) $m[1]] ?? '',
            $text,
        );
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
