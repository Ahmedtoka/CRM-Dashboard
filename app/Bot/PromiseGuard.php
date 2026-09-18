<?php

namespace App\Bot;

/**
 * Guards composed replies against invented promises (final fix wave I6), next to
 * PriceGuard's invented numbers: a discount, a free item, a gift or a guarantee
 * word may only appear in the reply when an approved script, fact or template of
 * the same turn already says it. Compared after ArabicNormalizer::normalize.
 */
class PromiseGuard
{
    public const PROMISE_WORDS = [
        'خصم', 'مجان', 'مجاني', 'مجانا', 'هدية', 'ضمان', 'استرداد كامل', 'بدون مصاريف', 'من غير مصاريف',
        'free', 'discount', 'gift', 'guarantee',
    ];

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    /** @param  list<string>  $approvedTexts */
    public function isSafe(string $reply, array $approvedTexts): bool
    {
        $reply = $this->normalizer->normalize($reply);
        $approved = $this->normalizer->normalize(implode("\n", $approvedTexts));

        foreach (self::PROMISE_WORDS as $word) {
            $word = $this->normalizer->normalize($word);

            if (str_contains($reply, $word) && ! str_contains($approved, $word)) {
                return false;
            }
        }

        return true;
    }
}
