<?php

namespace App\Bot;

/**
 * Guards AI replies against invented prices: every number ≥ 10 mentioned in
 * the reply must appear somewhere in the supplied catalog lines (spec §5.7
 * step 4 guardrails).
 */
class PriceGuard
{
    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    public function isSafe(string $reply, array $catalogLines): bool
    {
        $catalogNumbers = $this->numbers($this->stripSkus($catalogLines));

        foreach ($this->numbers($reply) as $number) {
            if ($number >= 10 && ! in_array($number, $catalogNumbers, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string[]  $lines
     */
    private function stripSkus(array $lines): string
    {
        $text = implode(' ', $lines);

        return preg_replace('/SKU\s+\S+/u', '', $text) ?? $text;
    }

    /**
     * @return float[]
     */
    private function numbers(string $text): array
    {
        // Arabic-Indic/Persian digits -> Latin, without the rest of
        // ArabicNormalizer::normalize() (its repeated-letter collapse would
        // mangle a run of identical digits, e.g. "٩٩٩" -> "٩" -> "9").
        $text = $this->normalizer->digitsToLatin($text);

        // Drop thousands separators (",", Arabic "٬") between digits so
        // "1,250" / "1٬250" read as one number instead of "1" and "250".
        $text = preg_replace('/(?<=\d)[,\x{066C}](?=\d)/u', '', $text) ?? $text;

        preg_match_all('/\d+(?:\.\d+)?/', $text, $matches);

        return array_map(static fn (string $n) => (float) $n, $matches[0]);
    }
}
