<?php

namespace App\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single "stuck" definition (spec §11.2): a shipment that has gone quiet for the
 * configured `stuck_order_days` window, with a grace period for a shipment that is
 * brand new and has not logged its first event yet. Shared by
 * `CustomerOrderFlags::has_stuck_order` and the orders `?stuck=1` filter
 * (`OrderEndpoints::orderQuery`) so the two can never drift apart.
 */
final class StuckOrderScope
{
    /**
     * Orders that count as stuck: not cancelled/failed, with a shipment matching
     * {@see Shipment()}.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function apply(Builder $query, int $days): Builder
    {
        $cutoff = now()->subDays(max(1, $days));

        return $query
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Failed->value])
            ->whereHas('shipment', fn (Builder $s) => self::shipment($s, $cutoff));
    }

    /**
     * Not delivered/returned/cancelled, no event at or after the cutoff, and its last
     * known event time (if any) is null or before the cutoff. The final clause is the
     * grace period: a shipment with no events yet only counts once it (or its order)
     * predates the cutoff, so a shipment created seconds ago is never "stuck".
     *
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    public static function shipment(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereNotIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Returned->value, ShipmentStatus::Cancelled->value])
            ->whereDoesntHave('events', fn (Builder $e) => $e->where('occurred_at', '>=', $cutoff))
            ->where(fn (Builder $w) => $w->whereNull('last_event_at')->orWhere('last_event_at', '<', $cutoff))
            ->where(fn (Builder $w) => $w->whereHas('events')->orWhere('created_at', '<', $cutoff));
    }
}
