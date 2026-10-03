<?php

namespace App\Support;

/**
 * The one place that knows what an emoji is (spec 2026-10-01 §6: nothing the system writes carries one).
 * Explicit code-point ranges, so the result does not depend on the server's PCRE version.
 * Arrows ← → (U+2190/2192), «», digits, # and * are NOT matched.
 */
final class Emoji
{
    public const PATTERN = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}'
        .'\x{2194}-\x{2199}\x{21A9}\x{21AA}\x{25AA}-\x{25FE}\x{2934}\x{2935}\x{3030}\x{303D}\x{3297}\x{3299}'
        .'\x{00A9}\x{00AE}\x{2122}\x{2139}\x{24C2}\x{FE0E}\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u';

    public static function contains(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }

    public static function strip(string $text): string
    {
        if (! self::contains($text)) {
            return $text;
        }
        $out = (string) preg_replace(self::PATTERN, '', $text);
        $out = (string) preg_replace('/[ \t\x{00A0}]{2,}/u', ' ', $out);   // the gap an emoji leaves
        $out = (string) preg_replace('/[ \t]+(\R)/u', '$1', $out);         // trailing spaces on a line
        $out = (string) preg_replace('/(\R)[ \t]+/u', '$1', $out);         // leading spaces on a line

        return trim($out);
    }

    public static function stripDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::strip($value);
        }
        if (is_array($value)) {
            return array_map(fn ($v) => self::stripDeep($v), $value);
        }

        return $value;
    }
}
