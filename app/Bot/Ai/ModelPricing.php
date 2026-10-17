<?php

namespace App\Bot\Ai;

/**
 * USD cost of one Messages API call from its token counts (crm.anthropic.prices, per million
 * tokens). Cache reads and writes have their own price; a model without them falls back to the
 * API's usual multipliers (reads 0.1x, 5-minute writes 1.25x of the input price).
 */
final class ModelPricing
{
    public static function cost(?string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $price = config('crm.anthropic.prices', [])[(string) $model] ?? null;

        if (! is_array($price)) {
            return 0.0;
        }

        $in = (float) ($price['input'] ?? 0);
        $read = (float) ($price['cache_read'] ?? $in * 0.1);
        $write = (float) ($price['cache_write'] ?? $in * 1.25);

        return round(($input * $in + $output * (float) ($price['output'] ?? 0) + $cacheRead * $read + $cacheWrite * $write) / 1_000_000, 6);
    }

    /** Cost of a raw `usage` object as the API returns it. */
    public static function ofUsage(?string $model, array $usage): float
    {
        return self::cost(
            $model,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0),
            (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }
}
