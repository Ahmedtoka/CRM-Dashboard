<?php

namespace App\Ads\Platforms;

/**
 * One place that strips credentials from platform error text before it is thrown, logged or stored:
 * the literal secrets we hold, query-string tokens, JSON-style "access_token":"…" pairs and the
 * auth headers each platform uses (Bearer, developer-token, Access-Token).
 */
final class SecretScrubber
{
    private const JSON_KEYS = 'access_token|refresh_token|client_secret|developer_token|token|secret';

    /** @param  list<string|null>  $secrets */
    public static function scrub(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }

        return (string) preg_replace([
            '/((?:access_token|refresh_token|client_secret)=)[^&\s"\']+/i',
            '/("(?:'.self::JSON_KEYS.')"\s*:\s*")[^"]*(")/i',
            '/(Bearer\s+)[^\s,;"\']+/i',
            '/(developer-token:?\s*)[^\s,;"\']+/i',
            '/(Access-Token:?\s*)[^\s,;"\']+/i',
        ], ['$1***', '$1***$2', '$1***', '$1***', '$1***'], $text);
    }
}
