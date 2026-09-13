<?php

namespace App\Shopify\Client;

/**
 * Raw HTTP boundary for ShopifyClient. Implementations must not swallow
 * network/transport failures — let them throw so ShopifyClient can classify
 * and retry.
 */
interface ShopifyTransport
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @return array{status: int, json: array<mixed>, headers: array<string, mixed>}
     */
    public function post(string $url, array $headers, array $body): array;
}
