<?php

namespace App\Commerce\Data;

final readonly class CommerceResult
{
    public function __construct(
        public bool $success,
        public ?string $orderId = null,
        public ?string $orderNumber = null,
        public ?string $draftOrderId = null,
        public ?string $invoiceUrl = null,
        public ?string $error = null,
    ) {}
}
