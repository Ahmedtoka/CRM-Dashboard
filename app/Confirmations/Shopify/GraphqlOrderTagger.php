<?php

namespace App\Confirmations\Shopify;

use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Models\Order;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Client\ShopifyException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adds WhatsApp confirmation tags to the Shopify order with the `tagsAdd` mutation
 * (spec 2026-10-16, slice W3). The only Shopify write this feature makes: it never
 * cancels or edits the order. A failure (not connected, userErrors, transport) is
 * logged and answered false so TagSync keeps the tags for the sweep to retry.
 */
final class GraphqlOrderTagger implements ShopifyOrderTagger
{
    private const MUTATION = <<<'GQL'
        mutation tagsAdd($id: ID!, $tags: [String!]!) {
          tagsAdd(id: $id, tags: $tags) {
            node { id }
            userErrors { field message }
          }
        }
        GQL;

    public function __construct(private readonly ShopifyClient $client) {}

    public function add(Order $order, array $tags): bool
    {
        $tags = array_values(array_unique(array_filter(array_map('strval', $tags), 'filled')));

        if (blank($order->shopify_order_id)) {
            return false;
        }

        if ($tags === []) {
            return true;
        }

        $id = (string) $order->shopify_order_id;
        $gid = str_starts_with($id, 'gid://') ? $id : "gid://shopify/Order/{$id}";

        try {
            $this->client->mutate(self::MUTATION, ['id' => $gid, 'tags' => $tags], 'tagsAdd');

            return true;
        } catch (Throwable $e) {
            Log::warning('Shopify tagsAdd failed for order '.$order->id.': '.$e->getMessage(), [
                'tags' => $tags,
                'user_errors' => $e instanceof ShopifyException ? $e->userErrors : [],
            ]);

            return false;
        }
    }
}
