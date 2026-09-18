<?php

namespace App\Commerce\Data;

use Carbon\CarbonInterface;

/**
 * Combined order status (spec §6.1): Shopify payment/fulfillment next to the
 * carrier's latest step, plus the mismatch verdict.
 */
final readonly class OrderDisplayStatus
{
    public function __construct(
        public string $payment,
        public ?string $fulfillment,
        public ?string $shipmentStep,
        public ?CarbonInterface $shipmentAt,
        public bool $mismatch,
        public ?string $mismatchReason,
    ) {}

    /**
     * @return array{payment: string, fulfillment: ?string, shipment_step: ?string, shipment_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'payment' => $this->payment,
            'fulfillment' => $this->fulfillment,
            'shipment_step' => $this->shipmentStep,
            'shipment_at' => $this->shipmentAt?->toIso8601String(),
        ];
    }
}
