<?php

namespace App\Bot\Grounding;

use App\Bot\ArabicNormalizer;

/**
 * Colour and size synonyms (spec §4.3) used both to read Shopify variant
 * titles and to pick colours/sizes out of a customer's message. Callers pass
 * single tokens: a Latin "s"/"m"/"l" only ever matches a whole token.
 */
final class Synonyms
{
    public const COLORS = [
        'أحمر' => ['احمر', 'أحمر', 'حمرا', 'red'], 'أسود' => ['اسود', 'أسود', 'سودا', 'black'], 'أبيض' => ['ابيض', 'أبيض', 'بيضا', 'white'],
        'بيج' => ['بيج', 'beige'], 'لبني' => ['لبني', 'بيبي بلو', 'baby blue'], 'كحلي' => ['كحلي', 'نيفي', 'navy'], 'أزرق' => ['ازرق', 'أزرق', 'blue'],
        'أخضر' => ['اخضر', 'أخضر', 'green'], 'زيتي' => ['زيتي', 'olive'], 'بمبي' => ['بمبي', 'وردي', 'روز', 'pink'], 'رمادي' => ['رمادي', 'رصاصي', 'gray', 'grey'],
        'بني' => ['بني', 'كافيه', 'brown'], 'موف' => ['موف', 'بنفسجي', 'purple'], 'أصفر' => ['اصفر', 'أصفر', 'yellow'], 'نبيتي' => ['نبيتي', 'burgundy'],
    ];

    public const SIZES = [
        'XS' => ['xs'], 'S' => ['s', 'small', 'سمول'], 'M' => ['m', 'medium', 'ميديم'], 'L' => ['l', 'large', 'لارج'],
        'XL' => ['xl', 'اكس لارج', 'اكسلارج'], '2XL' => ['2xl', 'xxl', 'دبل اكس لارج', '2 اكس'], '3XL' => ['3xl', 'xxxl', '3 اكس'],
        'Free Size' => ['free size', 'فري سايز', 'مقاس واحد'],
    ];

    /** @var array<string, array<string, string>> normalised variant => canonical, per map */
    private static array $index = [];

    public static function color(string $word): ?string
    {
        return self::lookup('colors', self::COLORS, $word);
    }

    public static function size(string $word): ?string
    {
        return self::lookup('sizes', self::SIZES, $word);
    }

    /** @param  array<string, list<string>>  $map */
    private static function lookup(string $name, array $map, string $word): ?string
    {
        $normalizer = app(ArabicNormalizer::class);

        if (! isset(self::$index[$name])) {
            self::$index[$name] = [];
            foreach ($map as $canonical => $variants) {
                foreach ($variants as $v) {
                    $nv = $normalizer->normalize($v);
                    self::$index[$name][$nv] ??= $canonical;
                    self::$index[$name][self::stripArticle($nv)] ??= $canonical;
                }
            }
        }

        $n = self::stripArticle($normalizer->normalize(trim($word)));

        return $n === '' ? null : (self::$index[$name][$n] ?? null);
    }

    private static function stripArticle(string $word): string
    {
        return preg_replace('/^ال/u', '', $word) ?? $word;
    }
}
