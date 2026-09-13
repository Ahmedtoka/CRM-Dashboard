<?php

namespace App\Shipping\Data;

final readonly class ShipmentResult
{
    public function __construct(
        public bool $success,
        public ?string $trackingNumber = null,
        public ?string $error = null,
    ) {}
}
