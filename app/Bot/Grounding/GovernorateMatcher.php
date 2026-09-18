<?php

namespace App\Bot\Grounding;

use App\Bot\ArabicNormalizer;

/**
 * Finds the Egyptian governorate (ISO 3166-2:EG code, matching
 * `config('crm.eg_provinces')` and Shopify's province codes) mentioned in a
 * customer message, including common city names and spellings.
 *
 * A variant must start a word, optionally with an attached و / ال / ل / لل /
 * ب / بال prefix. "اكتوبر" alone is a month, so 6th of October needs "6" or
 * "مدينة". When two different governorates are mentioned ("من القاهرة
 * للجيزة") the result is null: a shipping quote would be a guess.
 */
final class GovernorateMatcher
{
    private const VARIANTS = [
        'C' => ['القاهره', 'القاهرة', 'cairo', 'مصر الجديده', 'مدينه نصر', 'المعادي', 'حلوان'],
        'GZ' => ['الجيزه', 'جيزه', 'giza', '6 اكتوبر', '6اكتوبر', 'مدينه اكتوبر', 'الشيخ زايد', 'الهرم', 'فيصل'],
        'ALX' => ['الاسكندريه', 'اسكندريه', 'اسكندرية', 'alex', 'alexandria'],
        'DK' => ['الدقهليه', 'المنصوره'],
        'SHR' => ['الشرقيه', 'الزقازيق'],
        'KB' => ['القليوبيه', 'بنها', 'شبرا الخيمه'],
        'GH' => ['الغربيه', 'طنطا', 'المحله'],
        'MNF' => ['المنوفيه', 'شبين الكوم'],
        'BH' => ['البحيره', 'دمنهور'],
        'KFS' => ['كفر الشيخ'],
        'DT' => ['دمياط'],
        'PTS' => ['بورسعيد', 'port said'],
        'IS' => ['الاسماعيليه'],
        'SUZ' => ['السويس', 'suez'],
        'FYM' => ['الفيوم'],
        'BNS' => ['بني سويف'],
        'MN' => ['المنيا'],
        'AST' => ['اسيوط'],
        'SHG' => ['سوهاج'],
        'KN' => ['قنا'],
        'LX' => ['الاقصر', 'luxor'],
        'ASN' => ['اسوان', 'aswan'],
        'BA' => ['البحر الاحمر', 'الغردقه', 'hurghada'],
        'MT' => ['مطروح', 'مرسى مطروح'],
        'WAD' => ['الوادي الجديد', 'الخارجه'],
        'JS' => ['جنوب سيناء', 'شرم الشيخ'],
        'SIN' => ['شمال سيناء', 'العريش'],
    ];

    /** @var list<array{0: string, 1: string}>|null [code, regex] */
    private ?array $patterns = null;

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    public function match(string $text): ?string
    {
        $normalized = $this->normalizer->normalize($text);

        if (trim($normalized) === '') {
            return null;
        }

        $found = [];

        foreach ($this->patterns() as [$code, $pattern]) {
            if (! isset($found[$code]) && preg_match($pattern, $normalized) === 1) {
                $found[$code] = true;
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    public function name(string $code): string
    {
        return (string) (config('crm.eg_provinces')[$code] ?? $code);
    }

    /** @return list<array{0: string, 1: string}> */
    private function patterns(): array
    {
        if ($this->patterns !== null) {
            return $this->patterns;
        }

        $patterns = [];
        foreach (self::VARIANTS as $code => $list) {
            foreach ($list as $variant) {
                $v = $this->normalizer->normalize($variant);
                $v = preg_replace('/^ال/u', '', $v) ?? $v;
                $patterns[$code.'|'.$v] = [
                    $code,
                    '/(?:^|\s)(?:و)?(?:بال|لل|ال|ل|ب)?'.preg_quote($v, '/').'(?=$|\s|[؟?.,،!])/u',
                ];
            }
        }

        return $this->patterns = array_values($patterns);
    }
}
