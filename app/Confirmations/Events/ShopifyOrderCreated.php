<?php

namespace App\Confirmations\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Shopify orders/create webhook was applied to a local order (slice W1). Raised only by
 * ShopifyWebhookProcessor for that topic: never by orders/updated, imports, backfills or reconcile.
 */
final class ShopifyOrderCreated
{
    use Dispatchable;

    /** @param  bool  $test  the payload's `test` flag (a Shopify test checkout) */
    public function __construct(public readonly int $orderId, public readonly bool $test = false) {}
}
