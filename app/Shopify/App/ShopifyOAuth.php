<?php

namespace App\Shopify\App;

use App\Shopify\Client\ShopifyException;

/**
 * Shopify's token endpoint for a Partners app. Bound like ShopifyTransport: the live client only
 * when crm.shopify.driver is exactly `live`, FakeShopifyOAuth everywhere else.
 *
 * Failures are ShopifyException: kind `auth` when Shopify refused the grant (the token or refresh
 * token is no good: a person must open the app again), `transport` for anything passing.
 */
interface ShopifyOAuth
{
    /** Token exchange: an App Bridge ID token for an expiring offline access token. */
    public function exchange(ShopifyApp $app, string $shop, string $idToken): TokenSet;

    /** A new access token (and a new, rotated refresh token) from the stored refresh token. */
    public function refresh(ShopifyApp $app, string $shop, string $refreshToken): TokenSet;
}
