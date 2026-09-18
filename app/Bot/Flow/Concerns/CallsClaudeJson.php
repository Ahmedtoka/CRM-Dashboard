<?php

namespace App\Bot\Flow\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * One raw-HTTP Messages API call that must return a JSON object. Asks for
 * structured outputs (`output_config.format` json_schema); if the API rejects
 * the request with a 400 (model or account without structured outputs) it
 * retries once without it, telling the model to answer with only the JSON,
 * and parses the first balanced `{...}` object. Other HTTP errors throw.
 */
trait CallsClaudeJson
{
    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * @param  list<array{role:string, content:string}>  $messages
     * @param  array<string, mixed>  $schema
     * @return array{json: ?array, input_tokens: int, output_tokens: int, latency_ms: int, stop_reason: ?string}
     */
    private function claudeJson(?string $apiKey, string $model, int $timeout, int $maxTokens, string $system, array $messages, array $schema): array
    {
        $body = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $messages];
        $headers = ['x-api-key' => (string) $apiKey, 'anthropic-version' => '2023-06-01'];
        $start = microtime(true);

        try {
            $response = Http::withHeaders($headers)->timeout($timeout)
                ->post(self::ANTHROPIC_ENDPOINT, $body + ['output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]]])
                ->throw();
        } catch (RequestException $e) {
            if ($e->response->status() !== 400) {
                throw $e;
            }

            $body['system'] = $system."\n\nRespond with ONLY the JSON object, no other text. It must match this JSON schema: "
                .json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $response = Http::withHeaders($headers)->timeout($timeout)->post(self::ANTHROPIC_ENDPOINT, $body)->throw();
        }

        $data = $response->json() ?? [];
        $text = collect($data['content'] ?? [])->filter(fn ($b) => ($b['type'] ?? null) === 'text')->pluck('text')->implode('');
        $object = $this->firstBalancedJsonObject($text);
        $decoded = $object !== null ? json_decode($object, true) : null;

        return [
            'json' => is_array($decoded) ? $decoded : null,
            'input_tokens' => (int) ($data['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($data['usage']['output_tokens'] ?? 0),
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            // Surfaced so a caller can tell a truncated answer (the output cap hit
            // before the model finished) from a clean one, instead of only seeing
            // that the JSON failed to parse.
            'stop_reason' => is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : null,
        ];
    }

    /** Copied from ClaudeAiResponder: first balanced `{...}`, string-literal aware. */
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
