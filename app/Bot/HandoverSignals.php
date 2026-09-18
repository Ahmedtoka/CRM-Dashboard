<?php

namespace App\Bot;

/**
 * Deterministic handover triggers checked before any rule or AI call
 * (spec §4.1): the bot never collects order details or recommends a size.
 */
final class HandoverSignals
{
    private const PURCHASE_PHRASES = ['عاوزه اطلب', 'عايزه اطلب', 'عايز اطلب', 'عاوز اطلب', 'هاخد', 'احجزيلي', 'احجزلي', 'اطلب', 'عايزه اشتري', 'عاوزه اشتري', 'اكدي الاوردر'];

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /** @param  list<string>|null  $keywords  the owner's handover keywords (BotSetting) */
    public function matchesKeyword(string $text, ?array $keywords): bool
    {
        $normalized = $this->normalizer->normalize($text);

        foreach ($keywords ?? [] as $keyword) {
            $k = $this->normalizer->normalize((string) $keyword);

            if ($k !== '' && str_contains($normalized, $k)) {
                return true;
            }
        }

        return false;
    }

    /** @return 'purchase'|'contact_details'|'size_recommendation'|null */
    public function detect(string $text): ?string
    {
        $n = $this->normalizer->normalize($text);

        if ($this->containsPhone($this->normalizer->digitsToLatin($text)) || preg_match('/(العنوان|عنواني)\s*[:：]?.*(شارع|عماره|برج|الدور|شقه)/u', $n)) {
            return 'contact_details';
        }

        if (preg_match('/(مقاسي\s*(ايه|كام)|انهي\s*مقاس|البس\s*مقاس|يناسبني\s*مقاس|وزني|طولي|\d{2,3}\s*(كيلو|كجم|kg)|\d{3}\s*(سم|cm))/u', $n)) {
            return 'size_recommendation';
        }

        foreach (self::PURCHASE_PHRASES as $phrase) {
            if (str_contains($n, $this->normalizer->normalize($phrase))) {
                return 'purchase';
            }
        }

        return null;
    }

    /**
     * An Egyptian mobile number written as ONE contiguous run (internal
     * spaces/dashes allowed, at most 13 characters plus an optional "+",
     * non-digit on both sides) — so "1500 1200 1100" is three prices, not a phone.
     */
    private function containsPhone(string $latinText): bool
    {
        preg_match_all('/(?<![\d+])\+?\d[\d \-]{8,11}\d(?!\d)/', $latinText, $matches);

        foreach ($matches[0] as $candidate) {
            if (preg_match('/^(?:\+?20|0)?1[0125]\d{8}$/', str_replace([' ', '-'], '', $candidate)) === 1) {
                return true;
            }
        }

        return false;
    }
}
