<?php

namespace App\Confirmations;

use App\Bot\ArabicNormalizer;

/**
 * Strict Egyptian-mobile normaliser for the WhatsApp confirmation (spec 2026-10-16): only
 * 010/011/012/015 + 8 digits come back, as '+20…'. Anything else (landlines, other countries,
 * typos) is null, so the confirmation is skipped instead of messaging a wrong number.
 */
final class EgyptPhone
{
    public static function toE164(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (new ArabicNormalizer)->digitsToLatin($raw)) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2); // international prefix: 0020…
        }

        $national = match (true) {
            str_starts_with($digits, '20') && strlen($digits) === 13 && $digits[2] === '0' => substr($digits, 3), // +20 01…
            str_starts_with($digits, '20') && strlen($digits) === 12 => substr($digits, 2),
            str_starts_with($digits, '0') && strlen($digits) === 11 => substr($digits, 1),
            strlen($digits) === 10 => $digits,
            default => null,
        };

        if ($national === null || ! preg_match('/^1[0125]\d{8}$/', $national)) {
            return null;
        }

        return '+20'.$national;
    }
}
