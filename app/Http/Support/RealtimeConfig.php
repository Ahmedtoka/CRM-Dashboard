<?php

namespace App\Http\Support;

/**
 * The public half of the realtime (Reverb, Pusher protocol) settings, handed to the
 * mobile app on login / refresh / me so it does not need build-time values
 * (API contract, "Auth" → `Realtime`). The app key is public (it already ships in
 * the web JS bundle); the secret and app id never leave the server.
 *
 * The endpoint is the PUBLIC one (review 01, I4): `crm.mobile.reverb` (CRM_MOBILE_REVERB_*,
 * else VITE_REVERB_*), and only when no public host is configured the server's own
 * REVERB_* connection settings.
 */
final class RealtimeConfig
{
    /**
     * @return array{driver: ?string, key?: ?string, host?: ?string, port?: int, scheme?: string}
     */
    public static function forClient(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['driver' => null];
        }

        $reverb = (array) config('broadcasting.connections.reverb', []);
        $public = (array) config('crm.mobile.reverb', []);

        // A public host comes with its own port/scheme (or the scheme's default port) — never
        // mixed with the internal port, which would point the phone at e.g. :8080.
        $source = filled($public['host'] ?? null)
            ? ['host' => $public['host'], 'port' => $public['port'] ?? null, 'scheme' => $public['scheme'] ?? 'https']
            : (array) ($reverb['options'] ?? []);

        $scheme = ($source['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $port = $source['port'] ?? null;

        return [
            'driver' => 'reverb',
            'key' => isset($reverb['key']) ? (string) $reverb['key'] : null,
            'host' => filled($source['host'] ?? null) ? (string) $source['host'] : null,
            'port' => filled($port) ? (int) $port : ($scheme === 'https' ? 443 : 80),
            'scheme' => $scheme,
        ];
    }
}
