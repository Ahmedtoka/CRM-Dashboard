<?php

namespace App\Shopify\App;

/** A Shopify ID token that passed IdTokenVerifier: which app, which store, and its claims. */
final class VerifiedIdToken
{
    /** @param  array<string, mixed>  $claims */
    public function __construct(
        public readonly ShopifyApp $app,
        public readonly string $shop,
        public readonly array $claims,
    ) {}
}
