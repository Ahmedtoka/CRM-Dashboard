<?php

namespace App\Ads\Alerts;

final class Stats
{
    /** @param  array<int|string, int|float|string>  $values */
    public static function median(array $values): ?float
    {
        $v = array_values(array_filter(array_map('floatval', $values), 'is_finite'));
        if ($v === []) {
            return null;
        }
        sort($v);
        $n = count($v);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2;
    }
}
