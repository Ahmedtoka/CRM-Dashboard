<?php

namespace App\Shopify\App;

use App\Shopify\Client\ShopifyException;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POST https://{shop}/admin/oauth/access_token (shopify.dev token exchange, expiring offline
 * tokens). Never logs or returns a secret in an error message.
 */
final class HttpShopifyOAuth implements ShopifyOAuth
{
    private const TIMEOUT_SECONDS = 15;

    public function exchange(ShopifyApp $app, string $shop, string $idToken): TokenSet
    {
        return $this->post($shop, [
            'client_id' => $app->clientId,
            'client_secret' => $app->clientSecret,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $idToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            'expiring' => '1',
        ]);
    }

    public function refresh(ShopifyApp $app, string $shop, string $refreshToken): TokenSet
    {
        return $this->post($shop, [
            'client_id' => $app->clientId,
            'client_secret' => $app->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /** @param  array<string, string>  $body */
    private function post(string $shop, array $body): TokenSet
    {
        if (strcasecmp($shop, ShopifyIntegration::DEMO_SHOP_DOMAIN) === 0 || ! IdTokenVerifier::isShopDomain($shop)) {
            throw new ShopifyException('transport', 'Refusing to request a Shopify token for this shop domain.');
        }

        try {
            $response = Http::acceptJson()->asJson()->timeout(self::TIMEOUT_SECONDS)
                ->post("https://{$shop}/admin/oauth/access_token", $body);
        } catch (Throwable $e) {
            throw new ShopifyException('transport', 'Shopify token request failed: '.class_basename($e));
        }

        $json = $response->json();

        if ($response->successful() && is_array($json) && filled($json['access_token'] ?? null)) {
            return TokenSet::fromResponse($json);
        }

        $error = is_array($json) ? (string) ($json['error'] ?? '') : '';
        $kind = in_array($response->status(), [400, 401, 403], true) ? 'auth' : 'transport';

        throw new ShopifyException($kind, 'Shopify token request refused (HTTP '.$response->status().($error !== '' ? ", {$error}" : '').')');
    }
}
