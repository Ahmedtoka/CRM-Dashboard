<?php

namespace App\Bot\Ai;

use App\Enums\CommentIntent;
use Illuminate\Support\Facades\Http;

/**
 * Real Anthropic-backed AI driver (spec §5.7 step 4): Haiku for
 * classification, Sonnet for the grounded reply. Both calls expect a single
 * JSON object as the model's entire text output; the first balanced `{...}`
 * object in the response is parsed defensively since models occasionally
 * wrap JSON in prose or code fences. HTTP errors raise an exception (via
 * `->throw()`) so BotEngine can turn them into a `handover('ai_error')`.
 */
class ClaudeAiResponder implements AiResponder
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $classifierModel,
        private readonly string $replyModel,
        private readonly int $timeout = 10,
    ) {}

    public function classify(string $text): Classification
    {
        $system = <<<'PROMPT'
            You classify an Egyptian Arabic e-commerce customer message.
            Respond with ONLY a JSON object, no other text:
            {"intent":"buy|question|complaint|spam|other","confidence":0-1,"needs_human":true|false}
            PROMPT;

        $start = microtime(true);

        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->post(self::ENDPOINT, [
                'model' => $this->classifierModel,
                'max_tokens' => 200,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $text],
                ],
            ])
            ->throw();

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $data = $response->json() ?? [];
        $usage = $data['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        $json = $this->extractJson($this->textFrom($data));

        if ($json === null) {
            return new Classification(CommentIntent::Other, 0.0, true, $this->classifierModel, $inputTokens, $outputTokens, $latencyMs);
        }

        $intent = CommentIntent::tryFrom((string) ($json['intent'] ?? '')) ?? CommentIntent::Other;

        return new Classification(
            $intent,
            (float) ($json['confidence'] ?? 0),
            (bool) ($json['needs_human'] ?? false),
            $this->classifierModel,
            $inputTokens,
            $outputTokens,
            $latencyMs,
        );
    }

    /**
     * @param  array<int, array{role: 'customer'|'agent', text: string}>  $history
     * @param  string[]  $catalogLines
     */
    public function reply(array $history, array $catalogLines, string $systemPrompt): AiReply
    {
        $system = $systemPrompt
            ."\n\nCatalog (use these prices only, never invent or discount):\n"
            .implode("\n", $catalogLines)
            ."\n\nRespond with ONLY a JSON object, no other text: {\"action\":\"reply|handover\",\"text\":\"...\"}."
            .' Reply in short Egyptian Arabic.';

        $messages = array_map(
            fn (array $h) => ['role' => $h['role'] === 'agent' ? 'assistant' : 'user', 'content' => $h['text']],
            $history
        );

        $start = microtime(true);

        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->post(self::ENDPOINT, [
                'model' => $this->replyModel,
                'max_tokens' => 400,
                'system' => $system,
                'messages' => $messages === [] ? [['role' => 'user', 'content' => '']] : $messages,
            ])
            ->throw();

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $data = $response->json() ?? [];
        $usage = $data['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        $json = $this->extractJson($this->textFrom($data));

        if ($json === null) {
            return new AiReply('handover', '', $this->replyModel, $inputTokens, $outputTokens, $latencyMs);
        }

        return new AiReply(
            is_string($json['action'] ?? null) ? $json['action'] : 'handover',
            (string) ($json['text'] ?? ''),
            $this->replyModel,
            $inputTokens,
            $outputTokens,
            $latencyMs,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'x-api-key' => (string) $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ];
    }

    private function textFrom(array $data): string
    {
        return collect($data['content'] ?? [])
            ->filter(fn ($block) => ($block['type'] ?? null) === 'text')
            ->pluck('text')
            ->implode('');
    }

    private function extractJson(string $text): ?array
    {
        $json = $this->firstBalancedJsonObject($text);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Scans for the first balanced `{...}` object in $text, respecting
     * (possibly escaped) string literals so a brace inside a JSON string
     * value never throws off the depth count. A naive greedy regex like
     * `/\{.*\}/s` would instead swallow everything up to the *last* `}` in
     * the whole response, including any trailing prose.
     */
    private function firstBalancedJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
