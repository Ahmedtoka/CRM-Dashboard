<?php

namespace App\Bot\Flow;

use App\Bot\ArabicNormalizer;
use App\Bot\Flows\EntityExtractor;

/**
 * Offline understanding (no API key, or Claude failed): catalog keyword
 * matching over the whole burst plus simple entity/mood regexes.
 */
class FakeTurnUnderstanding implements TurnUnderstanding
{
    private const NEGATIVE = ['وحش', 'وحشه', 'مشكله', 'زعلانه', 'نصب', 'اتاخر', 'مش كويس', 'زفت', 'سيئ', 'حرام عليكم'];

    public function __construct(private readonly ArabicNormalizer $normalizer) {}

    public function understand(array $history, array $burstTexts, array $catalog): Understanding
    {
        $raw = $this->normalizer->digitsToLatin(implode("\n", $burstTexts));
        $text = $this->normalizer->normalize($raw);

        $intents = [];

        foreach ($catalog as $c) {
            foreach ($c['hints'] ?? [] as $hint) {
                if ($this->contains($text, (string) $hint)) {
                    $intents[] = ['key' => (string) $c['key'], 'confidence' => 0.85];
                    break;
                }
            }
        }

        $entities = array_fill_keys(Understanding::ENTITY_KEYS, null);

        $entities['phone'] = EntityExtractor::phone($raw);
        $entities['order_ref'] = EntityExtractor::orderRef($raw);
        $entities['email'] = EntityExtractor::email($raw);

        $negative = collect(self::NEGATIVE)->contains(fn ($w) => $this->contains($text, $w));

        return new Understanding(
            $intents,
            $entities,
            $negative ? 'negative' : 'neutral',
            preg_match('/بسرعه|ضروري|دلوقتي/u', $text) === 1,
            $intents === [],
            $this->language($raw),
            'fake',
        );
    }

    private function contains(string $normalizedText, string $hint): bool
    {
        $h = $this->normalizer->normalize(trim($hint));

        if ($h === '') {
            return false;
        }

        // Latin hints match whole words only ("hi" must not match "this").
        if (preg_match('/^[\x20-\x7E]+$/', $h)) {
            return preg_match('/(?<![a-z0-9])'.preg_quote($h, '/').'(?![a-z0-9])/', $normalizedText) === 1;
        }

        return str_contains($normalizedText, $h);
    }

    private function language(string $text): string
    {
        if (preg_match('/\p{Arabic}/u', $text)) {
            return 'ar';
        }

        return preg_match('/[a-z][2357][a-z]|[2357][a-z]{2}/i', $text) ? 'franco' : (preg_match('/[a-z]/i', $text) ? 'en' : 'ar');
    }
}
