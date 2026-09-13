<?php

namespace App\Shipping\Contracts;

use App\Models\Order;
use App\Shipping\Data\ShipmentResult;

interface ShippingProvider
{
    public function name(): string;

    public function createShipment(Order $order): ShipmentResult;
}
