<?php

namespace App\Ads\Control\Write;

/**
 * Canonical JSON for hashing: object keys sorted recursively (lists keep their order), unescaped unicode and slashes, so
 * the same content always gives the same diff_hash / request_hash whatever order PHP built it in.
 */
final class Canonical
{
    /** @param  array<mixed>  $data */
    public static function json(array $data): string
    {
        return (string) json_encode(self::sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param  array<mixed>  $data */
    public static function hash(array $data): string
    {
        return hash('sha256', self::json($data));
    }

    private static function sort(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::sort(...), $v);
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return $v;
    }
}
