<?php

namespace App\Ads\Launch;

/**
 * EGP prices written in a caption (R section 3 "caption price = Shopify price"; reused by S5 price_mismatch). Only a number
 * next to a currency word counts, so sizes, percentages and quantities are never read as prices.
 */
final class CaptionPriceParser
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** Longest first, so "جنيه" never stops at "ج". */
    private const CURRENCY = '(?:جنيهات|جنيه|جنية|ج\.\s?م|ج|EGP|L\.E\.?|LE)';

    private const NUMBER = '(\d{2,7})(?:[.٫]\d{1,2})?';

    /** @return int[] */
    public static function prices(string $text): array
    {
        $t = strtr($text, self::DIGITS);
        // Thousands separators inside a number: 1,250 / 1٬250 / 1.250 (exactly three digits after).
        $t = (string) preg_replace('/(?<=\d)[,٬.](?=\d{3}(?!\d))/u', '', $t);

        $found = [];
        if (preg_match_all('/'.self::NUMBER.'\s{0,2}'.self::CURRENCY.'(?![\p{L}])/iu', $t, $m)) {
            foreach ($m[1] as $v) {
                $found[] = (int) $v;
            }
        }
        if (preg_match_all('/(?<![\p{L}])'.self::CURRENCY.'\s{0,2}'.self::NUMBER.'/iu', $t, $m)) {
            foreach ($m[1] as $v) {
                $found[] = (int) $v;
            }
        }

        return array_values(array_unique($found));
    }
}
