<?php

namespace App\Confirmations\Contracts;

use App\Models\Order;

/**
 * Adds tags to the Shopify order (slice W3). The only Shopify write this feature makes.
 * Returns false when the write failed and should be retried later.
 */
interface ShopifyOrderTagger
{
    /** @param  list<string>  $tags */
    public function add(Order $order, array $tags): bool;
}
