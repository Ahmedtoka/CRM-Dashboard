<?php

namespace App\Bot\Flow\Orders;

/** The warehouse/order-management system (spec §2.1). Driver `crm.drivers.oms`. */
interface OmsClient
{
    /**
     * @return OmsStatus|null null when the OMS does not know the order
     *
     * @throws \Throwable on transport/server failure (OrderLookup falls back to Shopify data)
     */
    public function status(string $orderNumber): ?OmsStatus;
}
