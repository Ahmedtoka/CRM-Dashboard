<?php

namespace App\Bot;

use App\Bot\Flows\EntityExtractor;

/**
 * Guards a model reply against invented order facts (bot-hybrid.md §3.6), next to PriceGuard and
 * PromiseGuard: an order number or a date in the reply must appear in a tool result, the owner's
 * knowledge or her own words, and a delivery status («اتشحن», «اتسلم», «في الطريق»…) may only be
 * stated when an order tool ran this turn.
 */
class OrderFactGuard
{
    /** Normalized status phrases that claim something about a specific order. */
    private const STATUS_WORDS = ['اتشحن', 'اتسلم', 'خرج للتوصيل', 'في الطريق ليكي', 'في الطريق لحضرتك', 'مع المندوب', 'اتلغي', 'اتاكد', 'جاري تجهيزه', 'بيتجهز', 'وصل الفرع'];

    private const MONTHS = 'يناير|فبراير|مارس|ابريل|أبريل|مايو|يونيو|يوليو|اغسطس|أغسطس|سبتمبر|اكتوبر|أكتوبر|نوفمبر|ديسمبر';

    private const ORDER_TOOLS = ['get_order_status', 'get_order_items'];

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /**
     * @param  list<string>  $evidence  tool results, knowledge, store url
     * @param  list<string>  $customerTexts  what she wrote (her own order number may be repeated back)
     * @param  list<string>  $toolsUsed
     */
    public function isSafe(string $reply, array $evidence, array $customerTexts, array $toolsUsed): bool
    {
        $haystack = $this->normalizer->digitsToLatin(implode("\n", [...$evidence, ...$customerTexts]));

        foreach (EntityExtractor::orderRefs($reply) as $ref) {
            if (! str_contains($haystack, $ref)) {
                return false;
            }
        }

        $latin = $this->normalizer->digitsToLatin($reply);
        preg_match_all('~(?<!\d)\d{1,2}[/.\-]\d{1,2}(?:[/.\-]\d{2,4})?(?!\d)|(?<!\d)\d{1,2}\s+(?:'.self::MONTHS.')~u', $latin, $dates);

        foreach ($dates[0] as $date) {
            if (! str_contains($haystack, $date)) {
                return false;
            }
        }

        if (array_intersect($toolsUsed, self::ORDER_TOOLS) === []) {
            $norm = $this->normalizer->normalize($reply);

            foreach (self::STATUS_WORDS as $word) {
                if (str_contains($norm, $this->normalizer->normalize($word))) {
                    return false;
                }
            }
        }

        return true;
    }
}
