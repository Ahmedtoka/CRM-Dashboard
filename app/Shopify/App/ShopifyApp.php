<?php

namespace App\Shopify\App;

/**
 * One Partners app the CRM can be installed through (config crm.shopify.apps, D2): the Le Voile
 * custom-distribution app today, the public app later. Secrets never leave the server.
 */
final class ShopifyApp
{
    /**
     * @param  list<string>  $allowedShops  lower-case *.myshopify.com domains; empty = any store
     */
    public function __construct(
        public readonly string $key,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly ?string $previousSecret = null,
        public readonly array $allowedShops = [],
    ) {}

    public function allowsShop(string $shop): bool
    {
        return $this->allowedShops === [] || in_array(strtolower($shop), $this->allowedShops, true);
    }

    /**
     * The secrets a signature may be made with, labelled for WebhookEvent::verified_with.
     *
     * @return array<string, string> label => secret
     */
    public function secrets(): array
    {
        return array_filter([
            "app:{$this->key}" => $this->clientSecret,
            "app_prev:{$this->key}" => (string) $this->previousSecret,
        ], fn (string $secret) => $secret !== '');
    }
}
