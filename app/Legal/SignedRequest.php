<?php

namespace App\Legal;

/**
 * Meta's `signed_request`: "<base64url HMAC-SHA256 signature>.<base64url JSON payload>",
 * the signature computed over the encoded payload with the app secret.
 *
 * @see https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback
 */
final class SignedRequest
{
    /**
     * The decoded payload, or null when the request is malformed, uses another
     * algorithm, the secret is missing or the signature does not match.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(?string $signedRequest, ?string $secret): ?array
    {
        if ($signedRequest === null || $signedRequest === '' || $secret === null || $secret === '') {
            return null;
        }

        $parts = explode('.', $signedRequest, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$encodedSignature, $encodedPayload] = $parts;

        $signature = self::base64UrlDecode($encodedSignature);
        $json = self::base64UrlDecode($encodedPayload);

        if ($signature === null || $json === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $encodedPayload, $secret, true);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload) || strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        return $payload;
    }

    /** Builds a signed_request (tests and local tooling). */
    public static function make(array $payload, string $secret): string
    {
        $encodedPayload = self::base64UrlEncode((string) json_encode($payload + ['algorithm' => 'HMAC-SHA256']));

        return self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $secret, true)).'.'.$encodedPayload;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
