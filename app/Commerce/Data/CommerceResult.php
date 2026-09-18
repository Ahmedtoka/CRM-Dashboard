<?php

namespace App\Commerce\Data;

final readonly class CommerceResult
{
    /**
     * @param  string|null  $total  the store's own total ("0.00"), when it reports one (payment links)
     */
    public function __construct(
        public bool $success,
        public ?string $orderId = null,
        public ?string $orderNumber = null,
        public ?string $draftOrderId = null,
        public ?string $invoiceUrl = null,
        public ?string $error = null,
        public ?string $total = null,
    ) {}
}
