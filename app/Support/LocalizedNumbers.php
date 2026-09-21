<?php

namespace App\Support;

/**
 * Numbers in the viewer's own script, matching what the front end does with
 * `Intl.NumberFormat('ar-EG'|'en-EG')` (resources/js/i18n) — so a PHP-built line
 * and the Vue chrome around it never show two digit systems in the same panel.
 *
 * The `intl` extension is not enabled here, so the Arabic form is produced by
 * mapping the digits and the two separators onto their Arabic-Indic equivalents.
 *
 * Identifiers (case ids, order numbers) are deliberately NOT passed through this:
 * the dashboard renders them LTR in Latin everywhere.
 */
final class LocalizedNumbers
{
    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /** ARABIC THOUSANDS SEPARATOR (U+066C). */
    private const ARABIC_GROUP = '٬';

    /** ARABIC DECIMAL SEPARATOR (U+066B). */
    private const ARABIC_DECIMAL = '٫';

    /** A whole number: "1,250" / "١٬٢٥٠". */
    public static function integer(int $value, ?string $locale = null): string
    {
        return self::localize(number_format($value), $locale);
    }

    /** An amount, with decimals only when it has them: "1,250" / "٩٠٫٥٠". */
    public static function amount(float $value, ?string $locale = null): string
    {
        return self::localize(number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2), $locale);
    }

    /** The digits inside an already-built fragment, such as a date ("12/9" → "١٢/٩"). */
    public static function digits(string $text, ?string $locale = null): string
    {
        return self::isArabic($locale) ? strtr($text, array_combine(range('0', '9'), self::ARABIC_DIGITS)) : $text;
    }

    private static function localize(string $formatted, ?string $locale): string
    {
        if (! self::isArabic($locale)) {
            return $formatted;
        }

        return strtr($formatted, array_combine(
            [',', '.', ...range('0', '9')],
            [self::ARABIC_GROUP, self::ARABIC_DECIMAL, ...self::ARABIC_DIGITS],
        ));
    }

    private static function isArabic(?string $locale): bool
    {
        return str_starts_with($locale ?? app()->getLocale(), 'ar');
    }
}
