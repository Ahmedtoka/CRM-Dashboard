<?php

namespace App\Shopify\Client;

use Illuminate\Support\Facades\Http;

/**
 * Live transport used when `crm.shopify.driver` is not `fake`. Network and
 * non-2xx responses are surfaced as-is (status + json); connection-level
 * exceptions are allowed to propagate so ShopifyClient can classify them as
 * 'transport' failures and retry.
 */
final class HttpShopifyTransport implements ShopifyTransport
{
    public function post(string $url, array $headers, array $body): array
    {
        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->post($url, $body);

        return [
            'status' => $response->status(),
            'json' => $response->json() ?? [],
            'headers' => $response->headers(),
        ];
    }
}
