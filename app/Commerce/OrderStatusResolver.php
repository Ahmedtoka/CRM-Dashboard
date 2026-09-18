<?php

namespace App\Commerce;

use App\Commerce\Data\OrderDisplayStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\UserNotified;
use App\Models\Order;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Shopify\Connection\IntegrationRepository;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Combines Shopify payment/fulfillment with the carrier's latest step and
 * decides whether they disagree (spec §6.1). Time-based rules are re-evaluated
 * by the hourly `orders:detect-mismatch` command.
 */
final class OrderStatusResolver
{
    public const FULFILLED_BUT_RETURNED = 'fulfilled_but_returned';

    public const CANCELLED_BUT_IN_TRANSIT = 'cancelled_but_in_transit';

    public const DELIVERED_BUT_UNFULFILLED = 'delivered_but_unfulfilled';

    public const COD_DELIVERED_UNPAID = 'cod_delivered_unpaid';

    /**
     * Set at submission (OrderService) when Shopify's draft total differs from the
     * CRM total. Carrier rules never clear it; a carrier reason takes display
     * precedence while it applies, and this reason comes back when that clears.
     */
    public const SHOPIFY_TOTAL_DIFFERS = 'shopify_total_differs';

    /** Prefix of the Arabic last_error written with a total mismatch (before the reason was stored). */
    private const TOTAL_DIFFERS_ERROR_PREFIX = 'إجمالي Shopify';

    private const IN_MOTION = [ShipmentStatus::PickedUp, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered];

    public function __construct(private readonly IntegrationRepository $integrations) {}

    public function resolve(Order $order, ?CarbonInterface $now = null): OrderDisplayStatus
    {
        $now = CarbonImmutable::instance($now ?? now());
        [$step, $at] = $this->latestStep($order);

        $reason = $this->mismatchReason($order, $step, $at, $now)
            ?? ($this->hasTotalMismatch($order) ? self::SHOPIFY_TOTAL_DIFFERS : null);

        return new OrderDisplayStatus(
            payment: $order->financial_status ?? ($order->paid_at !== null ? 'paid' : 'pending'),
            fulfillment: $order->fulfillment_status ?? ($order->shopify_order_id !== null ? 'unfulfilled' : null),
            shipmentStep: $step?->value,
            shipmentAt: $at,
            mismatch: $reason !== null,
            mismatchReason: $reason,
        );
    }

    /**
     * Persists mismatch + reason, resolved against the locked row. Supervisors and
     * admins are notified once per order per reason — every reason already sent is
     * kept in `mismatch_notified_reasons` — only while `mismatch_alerts` is on.
     */
    public function refresh(Order $order): Order
    {
        $alerts = (bool) ($this->integrations->current()?->settingsWithDefaults()['mismatch_alerts'] ?? true);

        [$fresh, $notifyReason] = DB::transaction(function () use ($order, $alerts) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null) {
                return [$order, null];
            }

            $locked->loadMissing('shipment.events');
            $display = $this->resolve($locked);

            // A Shopify total mismatch is sticky: it stays the stored reason (so no
            // carrier rule can ever clear it) while carrier reasons still drive
            // the display reason and notifications.
            $totalDiffers = $this->hasTotalMismatch($locked);

            $locked->forceFill([
                'mismatch' => $display->mismatch || $totalDiffers,
                'mismatch_reason' => $totalDiffers ? self::SHOPIFY_TOTAL_DIFFERS : $display->mismatchReason,
            ]);

            $notified = array_values((array) ($locked->mismatch_notified_reasons ?? []));
            $notifyReason = null;

            if ($display->mismatch && $alerts && ! in_array($display->mismatchReason, $notified, true)) {
                $notifyReason = $display->mismatchReason;
                $locked->mismatch_notified_reasons = [...$notified, $notifyReason];
            }

            if ($locked->isDirty()) {
                $locked->save();
            }

            return [$locked, $notifyReason];
        });

        if ($notifyReason !== null) {
            $this->notify($fresh, $notifyReason);
        }

        return $fresh->setRelations(array_merge($order->getRelations(), $fresh->getRelations()));
    }

    /**
     * @return array{0: ?ShipmentStatus, 1: ?CarbonImmutable}
     */
    private function latestStep(Order $order): array
    {
        $shipment = $order->shipment;

        if ($shipment === null) {
            return [null, null];
        }

        /** @var ShipmentEvent|null $latest */
        $latest = $shipment->events
            ->sortBy(fn (ShipmentEvent $e) => [$e->occurred_at?->getTimestamp() ?? 0, $e->id])
            ->last();

        if ($latest !== null) {
            return [$latest->status, $latest->occurred_at ? CarbonImmutable::instance($latest->occurred_at) : null];
        }

        return [$shipment->status, $shipment->last_event_at ? CarbonImmutable::instance($shipment->last_event_at) : null];
    }

    private function mismatchReason(Order $order, ?ShipmentStatus $step, ?CarbonImmutable $at, CarbonImmutable $now): ?string
    {
        if ($step === null) {
            return null;
        }

        $olderThan = fn (int $hours) => $at !== null && $at->lessThan($now->subHours($hours));

        if ($order->fulfillment_status === 'fulfilled'
            && in_array($step, [ShipmentStatus::Returned, ShipmentStatus::FailedAttempt], true)
            && $olderThan(48)) {
            return self::FULFILLED_BUT_RETURNED;
        }

        if (($order->status === OrderStatus::Cancelled || $order->cancelled_at !== null) && in_array($step, self::IN_MOTION, true)) {
            return self::CANCELLED_BUT_IN_TRANSIT;
        }

        if ($step !== ShipmentStatus::Delivered) {
            return null;
        }

        // Orders never pushed to Shopify have no fulfillment to disagree with.
        if ($order->shopify_order_id !== null
            && in_array($order->fulfillment_status, [null, 'unfulfilled'], true)
            && $olderThan(24)) {
            return self::DELIVERED_BUT_UNFULFILLED;
        }

        if ($order->type === OrderType::Cod && $order->financial_status === 'pending' && $olderThan(72)) {
            return self::COD_DELIVERED_UNPAID;
        }

        return null;
    }

    private function hasTotalMismatch(Order $order): bool
    {
        if ($order->mismatch_reason === self::SHOPIFY_TOTAL_DIFFERS) {
            return true;
        }

        return (bool) $order->mismatch && str_starts_with((string) $order->last_error, self::TOTAL_DIFFERS_ERROR_PREFIX);
    }

    /**
     * @param  string  $reason  the live reason that triggered this alert (not the sticky stored one)
     */
    private function notify(Order $order, string $reason): void
    {
        $data = [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'shopify_order_name' => $order->shopify_order_name,
            'reason' => $reason,
        ];

        User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Supervisor->value, UserRole::Admin->value])
            ->get()
            ->each(fn (User $u) => SafeBroadcast::send(new UserNotified($u->id, 'order.mismatch', $data)));
    }
}
