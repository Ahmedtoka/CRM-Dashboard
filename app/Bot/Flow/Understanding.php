<?php

namespace App\Bot\Flow;

/** What the customer asked in one burst (spec §2.1): every intent, entities, mood and language. */
final readonly class Understanding
{
    public const ENTITY_KEYS = ['order_ref', 'phone', 'email', 'governorate', 'product', 'size', 'color'];

    /**
     * @param  list<array{key:string, confidence:float}>  $intents
     * @param  array<string, ?string>  $entities  order_ref, phone, email, governorate, product, size, color
     * @param  'positive'|'neutral'|'negative'  $sentiment
     * @param  'ar'|'franco'|'en'  $language
     */
    public function __construct(
        public array $intents,
        public array $entities,
        public string $sentiment,
        public bool $urgent,
        public bool $unclear,
        public string $language,
        public string $model = '',
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $latencyMs = 0,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_map(fn (array $i) => (string) $i['key'], $this->intents));
    }

    public function topConfidence(): float
    {
        return $this->intents === [] ? 0.0 : (float) max(array_map(fn (array $i) => (float) $i['confidence'], $this->intents));
    }
}
