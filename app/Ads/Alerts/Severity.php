<?php

namespace App\Ads\Alerts;

final class Severity
{
    public const CRITICAL = 'critical';

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const INFO = 'info';

    private const RANK = [self::CRITICAL => 4, self::HIGH => 3, self::MEDIUM => 2, self::INFO => 1];

    public static function rank(string $severity): int
    {
        return self::RANK[$severity] ?? 0;
    }

    public static function max(string $a, string $b): string
    {
        return self::rank($a) >= self::rank($b) ? $a : $b;
    }
}
