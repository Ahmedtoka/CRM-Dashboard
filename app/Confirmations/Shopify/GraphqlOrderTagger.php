<?php

namespace App\Confirmations\Shopify;

use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Models\Order;
use LogicException;

/** Placeholder: slice W3 implements the Shopify tagsAdd mutation. */
final class GraphqlOrderTagger implements ShopifyOrderTagger
{
    public function add(Order $order, array $tags): bool
    {
        throw new LogicException('GraphqlOrderTagger is not implemented yet (slice W3).');
    }
}
