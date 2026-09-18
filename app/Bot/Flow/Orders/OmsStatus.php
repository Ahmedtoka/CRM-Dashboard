<?php

namespace App\Bot\Flow\Orders;

use Carbon\CarbonImmutable;

final readonly class OmsStatus
{
    public const STATES = ['hold', 'prepared', 'shipped', 'on_the_way', 'delivered', 'returned', 'cancelled'];

    /** @param  'hold'|'prepared'|'shipped'|'on_the_way'|'delivered'|'returned'|'cancelled'  $state */
    public function __construct(
        public string $state,
        public ?CarbonImmutable $updatedAt,
        public ?string $courier,
        public ?string $trackingUrl,
    ) {}
}
