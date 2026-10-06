<?php

namespace App\Shopify\Webhooks;

use RuntimeException;

/**
 * A fulfillment/refund webhook names an order this CRM does not have: created before the data floor (never
 * imported), or its orders/create has not been processed yet. ProcessShopifyWebhook retries it for a few minutes
 * (the orders/create race) and then marks the event ignored instead of failing it.
 */
final class ParentOrderMissing extends RuntimeException {}
