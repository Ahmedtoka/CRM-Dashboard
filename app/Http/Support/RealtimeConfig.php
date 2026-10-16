<?php

namespace App\Http\Support;

/**
 * The public half of the realtime (Reverb, Pusher protocol) settings, handed to the
 * mobile app on login / refresh / me so it does not need build-time values
 * (API contract, "Auth" → `Realtime`). The app key is public (it already ships in
 * the web JS bundle); the secret and app id never leave the server.
 */
final class RealtimeConfig
{
    /**
     * @return array{driver: ?string, key?: ?string, host?: ?string, port?: ?int, scheme?: string}
     */
    public static function forClient(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['driver' => null];
        }

        $reverb = (array) config('broadcasting.connections.reverb', []);
        $options = (array) ($reverb['options'] ?? []);
        $scheme = ($options['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $port = $options['port'] ?? null;

        return [
            'driver' => 'reverb',
            'key' => isset($reverb['key']) ? (string) $reverb['key'] : null,
            'host' => isset($options['host']) ? (string) $options['host'] : null,
            'port' => $port !== null && $port !== '' ? (int) $port : ($scheme === 'https' ? 443 : 80),
            'scheme' => $scheme,
        ];
    }
}
