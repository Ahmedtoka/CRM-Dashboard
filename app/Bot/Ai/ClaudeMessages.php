<?php

namespace App\Bot\Ai;

use Illuminate\Support\Facades\Http;

/**
 * One Messages API call (raw HTTP, like the rest of the bot — bot-hybrid.md §8). Adds the headers,
 * the server-side refusal fallback for the models that have one (Sonnet 5.5 on the Claude API:
 * `fallbacks: "default"` under `server-side-fallback-2026-07-01`; Haiku 5.5 has none), measures the
 * call and charges Sonnet spend to the daily cap (SonnetBudget, owner B3).
 */
class ClaudeMessages
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  array<string, mixed>  $body  the request (model, max_tokens, system, messages, …)
     * @return array{response: array<string, mixed>, ms: int}
     */
    public function send(array $body, int $timeout): array
    {
        $headers = ['x-api-key' => (string) config('crm.anthropic.key'), 'anthropic-version' => '2023-06-01'];

        if (self::hasServerFallback((string) ($body['model'] ?? ''))) {
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
            $body['fallbacks'] = 'default';
        }

        $started = microtime(true);
        $response = Http::withHeaders($headers)->timeout(max(1, $timeout))->post(self::ENDPOINT, $body)->throw()->json() ?? [];
        $ms = (int) round((microtime(true) - $started) * 1000);

        app(SonnetBudget::class)->charge((string) ($response['model'] ?? $body['model'] ?? ''), (array) ($response['usage'] ?? []));

        return ['response' => $response, 'ms' => $ms];
    }

    /** Sonnet 5.5 (and later Sonnets) can be re-run server-side on a refusal; Haiku cannot. */
    public static function hasServerFallback(string $model): bool
    {
        return str_starts_with($model, 'claude-sonnet-5-5');
    }

    /** The text blocks of a response (thinking blocks are skipped by type). */
    public static function text(array $response): string
    {
        return trim(collect((array) ($response['content'] ?? []))->where('type', 'text')->pluck('text')->implode("\n"));
    }
}
