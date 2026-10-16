<?php

namespace App\Commerce\Data;

final readonly class OrderStatusUpdate
{
    public function __construct(
        public ?string $shopifyOrderId,
        public ?string $draftOrderId,
        public ?string $financialStatus,
        public ?string $fulfillmentStatus,
        public bool $cancelled,
        public ?string $orderNumber = null,
        /** Our own order id from the `crm_order_id` note attribute, when present. */
        public ?string $crmOrderId = null,
    ) {}
}
