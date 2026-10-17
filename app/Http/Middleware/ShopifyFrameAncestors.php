<?php

namespace App\Http\Middleware;

use App\Shopify\App\IdTokenVerifier;
use App\Shopify\App\ShopifyApps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shopify iframe protection (shopify.dev "set up iframe protection"): the embedded page may only be
 * framed by Shopify admin and by the store it was opened for. The store comes from a verified ID
 * token when the controller has one, else from the `shop` query parameter when the app has an
 * allowlist (SHOPIFY_APP_ALLOWED_SHOP) that names it; otherwise only admin.shopify.com may frame it.
 */
class ShopifyFrameAncestors
{
    public const SHOP_ATTRIBUTE = 'shopify_shop';

    public function __construct(private readonly ShopifyApps $apps) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $verified = $request->attributes->get(self::SHOP_ATTRIBUTE);
        $app = $this->apps->primary();
        // The unverified `shop` parameter only counts against an explicit allowlist (review L2).
        $param = $request->query('shop');
        $shop = is_string($verified) ? $verified : ($app !== null && $app->allowedShops !== [] && is_string($param) ? $param : '');
        $ancestors = $shop !== '' && IdTokenVerifier::isShopDomain($shop) && $app?->allowsShop($shop)
            ? 'https://'.strtolower($shop).' https://admin.shopify.com'
            : 'https://admin.shopify.com';

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors};");
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
