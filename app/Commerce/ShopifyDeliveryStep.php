<?php

namespace App\Commerce;

use App\Enums\ShipmentStatus;
use App\Models\Order;

/**
 * The order's delivery step, read from Shopify only (fresh-orders F4: the CRM keeps no carrier tracking).
 * `orders.shipment_status` is the latest live fulfillment's shipment status (OrderMapper::syncShipmentStatus),
 * lower-cased from REST (`in_transit`) or GraphQL display status (`IN_TRANSIT`); `delivered_at` wins.
 * Plain "fulfilled" and a cancelled/voided label carry no step: callers fall back to `fulfillment_status`
 * (a cancelled ORDER is cancelled_at / status, never a delivery step).
 */
final class ShopifyDeliveryStep
{
    private const MAP = [
        'label_printed' => ShipmentStatus::Created,
        'label_purchased' => ShipmentStatus::Created,
        'confirmed' => ShipmentStatus::Created,
        'submitted' => ShipmentStatus::Created,
        'ready_for_pickup' => ShipmentStatus::Created,
        'picked_up' => ShipmentStatus::PickedUp,
        'in_transit' => ShipmentStatus::InTransit,
        'out_for_delivery' => ShipmentStatus::OutForDelivery,
        'attempted_delivery' => ShipmentStatus::FailedAttempt,
        'not_delivered' => ShipmentStatus::FailedAttempt,
        'failure' => ShipmentStatus::FailedAttempt,
        'delivered' => ShipmentStatus::Delivered,
        // Not a Shopify value today; kept so a returned state written by the store (or a later carrier sync) reads right.
        'returned' => ShipmentStatus::Returned,
    ];

    public static function of(Order $order): ?ShipmentStatus
    {
        if ($order->delivered_at !== null) {
            return ShipmentStatus::Delivered;
        }

        return self::MAP[strtolower(trim((string) $order->shipment_status))] ?? null;
    }
}
