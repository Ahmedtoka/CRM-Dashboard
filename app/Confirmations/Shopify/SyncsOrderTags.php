<?php

namespace App\Confirmations\Shopify;

use App\Models\Order;

/**
 * A ShopifyOrderTagger that can also drop superseded tags and say why a write failed.
 * TagSync uses it when the bound tagger implements it, and plain add() otherwise.
 */
interface SyncsOrderTags
{
    /**
     * Adds $add and removes $remove in one write. False when it failed (see lastFailure()).
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function replace(Order $order, array $add, array $remove): bool;

    /** Why the last add()/replace() returned false, null after a success. */
    public function lastFailure(): ?string;
}
