<?php

namespace App\Shopify\Client;

use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Support\Facades\Http;

/**
 * Live transport used when `crm.shopify.driver` is not `fake`. Network and
 * non-2xx responses are surfaced as-is (status + json); connection-level
 * exceptions are allowed to propagate so ShopifyClient can classify them as
 * 'transport' failures and retry.
 */
final class HttpShopifyTransport implements ShopifyTransport
{
    /** Seconds before one HTTP call gives up. */
    private int $timeout = 30;

    /** A copy whose calls give up after $seconds (interactive requests). */
    public function withTimeout(int $seconds): self
    {
        $copy = clone $this;
        $copy->timeout = max(1, $seconds);

        return $copy;
    }

    public function post(string $url, array $headers, array $body): array
    {
        // Independent of everything upstream (driver config, which integration
        // row got read): this transport must never reach the demo/seeder shop
        // domain over the network, no matter how it got asked to.
        if (parse_url($url, PHP_URL_HOST) === ShopifyIntegration::DEMO_SHOP_DOMAIN) {
            throw new ShopifyException('transport', 'Refusing to call the demo Shopify domain ('.ShopifyIntegration::DEMO_SHOP_DOMAIN.') over the live transport.');
        }

        $response = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->post($url, $body);

        return [
            'status' => $response->status(),
            'json' => $response->json() ?? [],
            'headers' => $response->headers(),
        ];
    }
}
