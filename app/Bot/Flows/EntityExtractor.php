<?php

namespace App\Bot\Flows;

use App\Bot\ArabicNormalizer;

/**
 * Order details in free text: an Egyptian mobile, an order reference (4+
 * digits that are not part of a mobile) and an email. Shared by the offline
 * turn understanding and the flow `order` step.
 */
final class EntityExtractor
{
    private const PHONE = '/(?<!\d)(?:\+?20|0)?(1[0125]\d{8})(?!\d)/';

    public static function phone(string $text): ?string
    {
        $raw = (new ArabicNormalizer)->digitsToLatin($text);

        return preg_match(self::PHONE, str_replace([' ', '-'], '', $raw), $m) ? '0'.$m[1] : null;
    }

    public static function orderRef(string $text): ?string
    {
        $raw = (new ArabicNormalizer)->digitsToLatin($text);
        $withoutPhones = preg_replace(self::PHONE, ' ', $raw) ?? $raw;

        return preg_match('/#?(?<!\d)(\d{4,})(?!\d)/', $withoutPhones, $m) ? $m[1] : null;
    }

    public static function email(string $text): ?string
    {
        return preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m) ? $m[0] : null;
    }
}
