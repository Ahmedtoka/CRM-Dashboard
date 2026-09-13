<?php

namespace App\Shopify\Sync\Mappers;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Small, pure helpers shared by the mappers to read Shopify REST and GraphQL payloads.
 *
 * @internal
 */
final class Payload
{
    public static function isGraphql(array $payload): bool
    {
        return is_string($payload['id'] ?? null) && str_starts_with($payload['id'], 'gid://');
    }

    /**
     * Numeric id string from a REST number, numeric string or `gid://shopify/X/123` id.
     */
    public static function id(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value) || is_bool($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) (int) $value;
        }

        $id = trim((string) $value);

        if (str_starts_with($id, 'gid://')) {
            $id = explode('?', $id, 2)[0];
            $id = substr($id, strrpos($id, '/') + 1);
        }

        return $id === '' ? null : $id;
    }

    /**
     * Two-decimal string; accepts strings, numbers and REST/GraphQL money objects.
     */
    public static function money(mixed $value): string
    {
        return number_format(self::amount($value) ?? 0.0, 2, '.', '');
    }

    public static function moneyOrNull(mixed $value): ?string
    {
        $amount = self::amount($value);

        return $amount === null ? null : number_format($amount, 2, '.', '');
    }

    public static function amount(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value['shop_money']['amount'] ?? $value['shopMoney']['amount'] ?? $value['amount'] ?? null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * Parses an ISO-8601 instant into the application timezone (storage is timezone-naive).
     */
    public static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    public static function lower(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }

    public static function string(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Unwraps GraphQL connections (`nodes` / `edges[].node`) or returns a plain list.
     *
     * @return list<array<string, mixed>>
     */
    public static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (array_key_exists('nodes', $value) && is_array($value['nodes'])) {
            return array_values($value['nodes']);
        }

        if (array_key_exists('edges', $value) && is_array($value['edges'])) {
            return array_values(array_filter(array_map(fn ($edge) => $edge['node'] ?? null, $value['edges'])));
        }

        return array_is_list($value) ? $value : [];
    }

    /**
     * REST "a, b" strings or GraphQL arrays to a clean list.
     *
     * @return list<string>
     */
    public static function tags(mixed $value): array
    {
        $tags = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);

        return array_values(array_filter(array_map(fn ($t) => trim((string) $t), $tags), fn ($t) => $t !== ''));
    }
}
