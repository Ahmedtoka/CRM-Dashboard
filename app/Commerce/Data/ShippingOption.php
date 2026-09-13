<?php

namespace App\Commerce\Data;

/**
 * One shipping choice for a governorate: a stored zone rate, or the default
 * fee (rateId null, title "شحن") when no zone serves it. Price is "0.00".
 */
final readonly class ShippingOption
{
    public function __construct(public ?int $rateId, public string $title, public string $price) {}
}
