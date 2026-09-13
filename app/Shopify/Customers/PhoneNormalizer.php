<?php

namespace App\Shopify\Customers;

use App\Bot\ArabicNormalizer;

/**
 * Normalizes raw, human-entered phone numbers into E.164 where the number
 * is (or can confidently be assumed to be) an Egyptian mobile, per spec
 * §3.3. Numbers that don't match a known transform are returned as a
 * digits-only string with no linking eligibility implied.
 */
final class PhoneNormalizer
{
    /**
     * Egyptian mobile operator prefixes (the two digits right after the
     * country code's leading "1").
     */
    private const EGYPT_MOBILE_PREFIXES = ['10', '11', '12', '15'];

    public static function toE164(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $normalized = (new ArabicNormalizer)->digitsToLatin($raw);
        $hasLeadingPlus = str_starts_with(trim($normalized), '+');
        $digits = preg_replace('/\D+/', '', $normalized) ?? '';

        if ($digits === '') {
            return null;
        }

        if ($hasLeadingPlus) {
            return '+'.$digits;
        }

        // 0020XXXXXXXXXXX -> strip the international trunk prefix "00".
        if (str_starts_with($digits, '0020') && strlen($digits) === 14) {
            return '+'.substr($digits, 2);
        }

        // 201XXXXXXXXX -> already has the country code, just add "+".
        if (str_starts_with($digits, '20') && strlen($digits) === 12) {
            return '+'.$digits;
        }

        // 01XXXXXXXXX (local format) -> drop the trunk "0", add "+20".
        if (str_starts_with($digits, '01') && strlen($digits) === 11) {
            return '+20'.substr($digits, 1);
        }

        return $digits;
    }

    public static function isEgyptianMobile(?string $e164): bool
    {
        if ($e164 === null) {
            return false;
        }

        if (! preg_match('/^\+20(\d{2})(\d{8})$/', $e164, $matches)) {
            return false;
        }

        return in_array($matches[1], self::EGYPT_MOBILE_PREFIXES, true);
    }
}
