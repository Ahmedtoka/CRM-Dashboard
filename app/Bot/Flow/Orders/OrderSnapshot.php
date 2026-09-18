<?php

namespace App\Bot\Flow\Orders;

use Carbon\CarbonImmutable;

/**
 * One order as the bot may talk about it: status only, never the customer's
 * name, address or phone (an order number alone is not proof of ownership).
 */
final readonly class OrderSnapshot
{
    /**
     * @param  'shopify'|'oms'|'shopify_fallback'  $source  shopify_fallback = the OMS call failed (spec §4)
     * @param  string  $statusKey  confirmed|prepared|shipped|on_the_way|delivered|cancelled|hold|returned
     * @param  bool  $failedAttempt  the carrier's latest step is a failed delivery attempt (shown as on_the_way)
     */
    public function __construct(
        public int $orderId,
        public string $number,
        public CarbonImmutable $placedAt,
        public string $source,
        public string $statusKey,
        public ?string $trackingUrl,
        public ?string $governorate,
        public bool $failedAttempt = false,
    ) {}
}
