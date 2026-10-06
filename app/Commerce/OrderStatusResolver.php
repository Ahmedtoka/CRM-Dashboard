<?php

namespace App\Commerce;

use App\Commerce\Data\OrderDisplayStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The order's combined status, from Shopify only (fresh-orders F4): payment, fulfilment and the delivery step of
 * ShopifyDeliveryStep. The carrier-based mismatch rules went with the CRM's shipment tracking; the one mismatch left
 * is a Shopify total that differs from the CRM total at submission (OrderService), which stays until it is fixed.
 */
final class OrderStatusResolver
{
    /**
     * Set at submission (OrderService) when Shopify's draft total differs from the CRM total.
     */
    public const SHOPIFY_TOTAL_DIFFERS = 'shopify_total_differs';

    /** Prefix of the Arabic last_error written with a total mismatch (before the reason was stored). */
    private const TOTAL_DIFFERS_ERROR_PREFIX = 'إجمالي Shopify';

    public function resolve(Order $order, ?CarbonInterface $now = null): OrderDisplayStatus
    {
        $step = ShopifyDeliveryStep::of($order);
        $differs = $this->hasTotalMismatch($order);

        return new OrderDisplayStatus(
            payment: $order->financial_status ?? ($order->paid_at !== null ? 'paid' : 'pending'),
            fulfillment: $order->fulfillment_status ?? ($order->shopify_order_id !== null ? 'unfulfilled' : null),
            shipmentStep: $step?->value,
            shipmentAt: $order->delivered_at !== null ? CarbonImmutable::instance($order->delivered_at) : null,
            mismatch: $differs,
            mismatchReason: $differs ? self::SHOPIFY_TOTAL_DIFFERS : null,
        );
    }

    /**
     * Persists mismatch + reason against the locked row: a stored carrier reason from before F4 clears, a Shopify
     * total mismatch stays.
     */
    public function refresh(Order $order): Order
    {
        $fresh = DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null) {
                return $order;
            }

            $differs = $this->hasTotalMismatch($locked);
            $locked->forceFill([
                'mismatch' => $differs,
                'mismatch_reason' => $differs ? self::SHOPIFY_TOTAL_DIFFERS : null,
            ]);

            if ($locked->isDirty()) {
                $locked->save();
            }

            return $locked;
        });

        return $fresh->setRelations(array_merge($order->getRelations(), $fresh->getRelations()));
    }

    private function hasTotalMismatch(Order $order): bool
    {
        if ($order->mismatch_reason === self::SHOPIFY_TOTAL_DIFFERS) {
            return true;
        }

        return (bool) $order->mismatch && str_starts_with((string) $order->last_error, self::TOTAL_DIFFERS_ERROR_PREFIX);
    }
}
