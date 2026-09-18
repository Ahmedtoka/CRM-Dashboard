<?php

namespace App\Bot\Flow;

use App\Bot\Flow\Concerns\CallsClaudeJson;

/**
 * One Claude call per turn over recent history + the whole burst (spec §2.1):
 * every intent, entities, sentiment, urgency, language. Unparsable output →
 * an `unclear` Understanding; HTTP failures throw (TurnRunner falls back).
 */
class ClaudeTurnUnderstanding implements TurnUnderstanding
{
    use CallsClaudeJson;

    public const SYSTEM_PROMPT = <<<'PROMPT'
        You read a customer's latest burst of messages to an Egyptian women's clothing brand (Arabic, Egyptian dialect, Franco-Arabic, or English), with recent chat history for context.
        Return every intent the customer expressed in the burst (0-4), using only these keys:
        {catalog}
        Extract entities if present: order_ref (order number, digits or #1234), phone, email, governorate, product, size, color.
        sentiment: negative only for anger/frustration. urgent: true for "بسرعة/ضروري/دلوقتي" style pressure or repeated chasing.
        unclear: true when you cannot tell what she wants. language: ar | franco | en.
        PROMPT;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 8,
    ) {}

    public function understand(array $history, array $burstTexts, array $catalog): Understanding
    {
        $keys = array_values(array_unique(array_map(fn (array $c) => (string) $c['key'], $catalog)));

        $result = $this->claudeJson(
            $this->apiKey,
            $this->model,
            $this->timeout,
            400,
            str_replace('{catalog}', $this->catalogLines($catalog), self::SYSTEM_PROMPT),
            $this->messages($history, $burstTexts),
            self::schema($keys),
        );

        return $this->parse($result['json'], $keys, $result['input_tokens'], $result['output_tokens'], $result['latency_ms']);
    }

    /**
     * Strict schema: every property required, no extra properties, entity
     * values string|null, intent keys restricted to the catalog when known.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function schema(array $keys): array
    {
        $key = ['type' => 'string'];

        if ($keys !== []) {
            $key['enum'] = $keys;
        }

        $entities = [];

        foreach (Understanding::ENTITY_KEYS as $e) {
            $entities[$e] = ['type' => ['string', 'null']];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intents' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['key' => $key, 'confidence' => ['type' => 'number']],
                        'required' => ['key', 'confidence'],
                    ],
                ],
                'entities' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => $entities,
                    'required' => Understanding::ENTITY_KEYS,
                ],
                'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
                'urgent' => ['type' => 'boolean'],
                'unclear' => ['type' => 'boolean'],
                'language' => ['type' => 'string', 'enum' => ['ar', 'franco', 'en']],
            ],
            'required' => ['intents', 'entities', 'sentiment', 'urgent', 'unclear', 'language'],
        ];
    }

    private function catalogLines(array $catalog): string
    {
        return collect($catalog)
            ->map(function (array $c) {
                $hints = array_slice(array_values(array_filter($c['hints'] ?? [], fn ($h) => trim((string) $h) !== '')), 0, 6);

                return $c['key'].': '.$c['label'].($hints !== [] ? ' (examples: '.implode(', ', $hints).')' : '');
            })
            ->implode("\n");
    }

    /**
     * History mapped to user/assistant, empty lines dropped, consecutive
     * same-role turns merged, must start with the customer; the burst is the
     * final user turn.
     *
     * @return list<array{role:string, content:string}>
     */
    private function messages(array $history, array $burstTexts): array
    {
        $turns = [];

        foreach ($history as $h) {
            $text = trim((string) ($h['text'] ?? ''));

            if ($text !== '') {
                $turns[] = ['role' => ($h['role'] ?? '') === 'customer' ? 'user' : 'assistant', 'content' => $text];
            }
        }

        $turns[] = ['role' => 'user', 'content' => "Latest burst:\n- ".implode("\n- ", $burstTexts)];

        while ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        $merged = [];

        foreach ($turns as $t) {
            $last = count($merged) - 1;

            if ($last >= 0 && $merged[$last]['role'] === $t['role']) {
                $merged[$last]['content'] .= "\n".$t['content'];
            } else {
                $merged[] = $t;
            }
        }

        return $merged;
    }

    /** @param  list<string>  $keys */
    private function parse(?array $json, array $keys, int $in, int $out, int $latency): Understanding
    {
        if ($json === null || ! is_array($json['intents'] ?? null)) {
            return new Understanding([], array_fill_keys(Understanding::ENTITY_KEYS, null), 'neutral', false, true, 'ar', $this->model, $in, $out, $latency);
        }

        $intents = [];

        foreach ($json['intents'] as $i) {
            $key = is_array($i) && is_string($i['key'] ?? null) ? $i['key'] : null;

            if ($key === null || ! is_numeric($i['confidence'] ?? null) || isset($intents[$key]) || ($keys !== [] && ! in_array($key, $keys, true))) {
                continue;
            }

            $intents[$key] = ['key' => $key, 'confidence' => max(0.0, min(1.0, (float) $i['confidence']))];
        }

        $entities = [];

        foreach (Understanding::ENTITY_KEYS as $e) {
            $v = $json['entities'][$e] ?? null;
            $entities[$e] = is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        }

        return new Understanding(
            array_slice(array_values($intents), 0, 4),
            $entities,
            in_array($json['sentiment'] ?? null, ['positive', 'neutral', 'negative'], true) ? $json['sentiment'] : 'neutral',
            ($json['urgent'] ?? false) === true,
            ($json['unclear'] ?? false) === true,
            in_array($json['language'] ?? null, ['ar', 'franco', 'en'], true) ? $json['language'] : 'ar',
            $this->model,
            $in,
            $out,
            $latency,
        );
    }
}
