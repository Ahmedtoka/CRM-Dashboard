<?php

namespace App\Shopify\App;

use JsonException;

/**
 * Verifies a Shopify ID (session) token from App Bridge (shopify.dev session tokens): an HS256 JWT
 * signed with the app's client secret, `aud` = the client id, `exp` in the future and `nbf` in the
 * past, `iss` = `dest` + "/admin" and `dest` = https://{shop}.myshopify.com. No library: the format
 * is small and fixed, and only HS256 is ever accepted (never `none` or another algorithm).
 */
final class IdTokenVerifier
{
    /** Clock skew allowed on exp/nbf, in seconds. */
    public const LEEWAY_SECONDS = 10;

    public function __construct(private readonly ShopifyApps $apps) {}

    /**
     * @throws InvalidIdToken anything wrong with the token itself (answer 401)
     * @throws ShopNotAllowed a valid token for a store this app is not for (answer 403)
     */
    public function verify(string $jwt): VerifiedIdToken
    {
        $parts = explode('.', trim($jwt));

        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new InvalidIdToken('malformed');
        }

        [$header, $payload, $signature] = array_map(fn (string $p) => self::decodePart($p), $parts);

        if ($header === null || $payload === null || $signature === null) {
            throw new InvalidIdToken('malformed');
        }

        $headerJson = self::json($header);
        $claims = self::json($payload);

        if (($headerJson['alg'] ?? null) !== 'HS256' || $claims === null) {
            throw new InvalidIdToken('unsupported');
        }

        $app = $this->apps->byClientId(is_string($claims['aud'] ?? null) ? $claims['aud'] : null);

        if ($app === null) {
            throw new InvalidIdToken('audience');
        }

        $signed = $parts[0].'.'.$parts[1];
        $valid = false;
        foreach ([$app->clientSecret, $app->previousSecret] as $secret) {
            if (filled($secret) && hash_equals(hash_hmac('sha256', $signed, (string) $secret, true), $signature)) {
                $valid = true;
                break;
            }
        }

        if (! $valid) {
            throw new InvalidIdToken('signature');
        }

        $now = time();
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - self::LEEWAY_SECONDS) {
            throw new InvalidIdToken('expired');
        }
        if (is_numeric($claims['nbf'] ?? null) && (int) $claims['nbf'] > $now + self::LEEWAY_SECONDS) {
            throw new InvalidIdToken('not_yet_valid');
        }

        $dest = (string) ($claims['dest'] ?? '');
        $shop = (string) parse_url($dest, PHP_URL_HOST);

        if (parse_url($dest, PHP_URL_SCHEME) !== 'https' || ! self::isShopDomain($shop)
            || rtrim((string) ($claims['iss'] ?? ''), '/') !== "https://{$shop}/admin") {
            throw new InvalidIdToken('issuer');
        }

        $shop = strtolower($shop);

        if (! $app->allowsShop($shop)) {
            throw new ShopNotAllowed($shop);
        }

        return new VerifiedIdToken($app, $shop, $claims);
    }

    /** A bare `{name}.myshopify.com` host (what Shopify sends as `shop`), nothing else. */
    public static function isShopDomain(string $shop): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i', $shop) === 1;
    }

    private static function decodePart(string $part): ?string
    {
        $decoded = base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    /** @return array<string, mixed>|null */
    private static function json(string $raw): ?array
    {
        try {
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }
}
