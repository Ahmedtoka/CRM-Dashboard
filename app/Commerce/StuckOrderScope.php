<?php

namespace App\Commerce;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single "stuck" definition (spec §11.2, re-based on Shopify by fresh-orders F4): an order Shopify shows as
 * fulfilled (or partly) and not delivered, whose last Shopify change is older than the `stuck_order_days` window.
 * Shared by `CustomerOrderFlags::has_stuck_order` and the orders `?stuck=1` filter so the two never drift apart.
 */
final class StuckOrderScope
{
    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function apply(Builder $query, int $days): Builder
    {
        $cutoff = now()->subDays(max(1, $days));

        return $query
            ->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Failed->value])
            ->whereNull('orders.cancelled_at')
            ->whereIn('orders.fulfillment_status', ['fulfilled', 'partial'])
            ->whereNot(fn (Builder $q) => $q->deliveredOnShopify())
            ->whereRaw('coalesce(orders.shopify_updated_at, orders.placed_at, orders.created_at) < ?', [$cutoff->toDateTimeString()]);
    }
}
