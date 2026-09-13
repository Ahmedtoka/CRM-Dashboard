<?php

namespace App\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Shipping\Contracts\ShippingProvider;
use App\Shipping\Data\ShipmentResult;
use Illuminate\Support\Str;

/**
 * Deterministic in-memory shipping driver (crm.drivers.shipping = 'fake').
 * The demo simulator advances shipments through nextStatus() over time.
 */
class FakeShippingProvider implements ShippingProvider
{
    public function name(): string
    {
        return 'fake-shipping';
    }

    public function createShipment(Order $order): ShipmentResult
    {
        return new ShipmentResult(
            success: true,
            trackingNumber: 'TRK'.Str::upper(Str::random(10)),
        );
    }

    /**
     * created -> picked_up -> in_transit -> out_for_delivery -> delivered.
     */
    public static function nextStatus(ShipmentStatus $status): ?ShipmentStatus
    {
        return match ($status) {
            ShipmentStatus::Created => ShipmentStatus::PickedUp,
            ShipmentStatus::PickedUp => ShipmentStatus::InTransit,
            ShipmentStatus::InTransit => ShipmentStatus::OutForDelivery,
            ShipmentStatus::OutForDelivery => ShipmentStatus::Delivered,
            default => null,
        };
    }
}
