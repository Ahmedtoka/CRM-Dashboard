<?php

namespace App\Confirmations\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Shopify orders/create webhook was applied to a local order (slice W1). Raised by
 * ShopifyWebhookProcessor for that topic, and by PollNewOrdersCommand (D4) for a recent order whose
 * orders/create never arrived: never by orders/updated, imports, backfills or reconcile.
 */
final class ShopifyOrderCreated
{
    use Dispatchable;

    /**
     * @param  bool  $test  the payload's `test` flag (a Shopify test checkout)
     * @param  string|null  $sourceName  the payload's `source_name` (web, pos, shopify_draft_order, …)
     */
    public function __construct(
        public readonly int $orderId,
        public readonly bool $test = false,
        public readonly ?string $sourceName = null,
    ) {}
}
