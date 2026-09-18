<?php

namespace App\Shopify\Customers;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Events\CustomerUpdated;
use App\Models\Customer;
use App\Models\Order;
use App\Shipping\StuckOrderScope;
use App\Shopify\Connection\IntegrationRepository;
use App\Support\SafeBroadcast;
use Illuminate\Database\Eloquent\Builder;

/**
 * Maintains the indexed filter columns on customers (spec §11.2). Every check is an
 * EXISTS/COUNT over this customer's own orders; nothing is loaded into memory.
 */
final class CustomerOrderFlags
{
    private const FLAGS = ['is_repeat', 'has_open_order', 'has_return', 'has_stuck_order'];

    public function __construct(private readonly IntegrationRepository $integrations) {}

    public function recompute(Customer $customer): void
    {
        $id = $customer->getKey();
        $orders = fn (): Builder => Order::query()->where('customer_id', $id);
        $notCancelled = [OrderStatus::Cancelled->value, OrderStatus::Failed->value];

        $shopifyCount = (int) Customer::query()->whereKey($id)->value('shopify_orders_count');
        $localCount = $orders()->whereNotIn('status', $notCancelled)->count();

        $finished = fn (Builder $q) => $q
            ->whereHas('shipment', fn (Builder $s) => $s->whereIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Returned->value]))
            ->orWhereHas('fulfillments', fn (Builder $f) => $f->where('shipment_status', 'delivered'));

        $days = (int) ($this->integrations->current()?->settingsWithDefaults()['stuck_order_days'] ?? 5);

        $flags = [
            'is_repeat' => max($shopifyCount, $localCount) >= 2,
            'has_open_order' => $orders()
                ->whereIn('status', [OrderStatus::Submitting->value, OrderStatus::AwaitingPayment->value, OrderStatus::Confirmed->value])
                ->whereNot($finished)
                ->exists(),
            'has_return' => $orders()
                ->where(fn (Builder $q) => $q
                    ->whereHas('refunds')
                    ->orWhereHas('shipment', fn (Builder $s) => $s->where('status', ShipmentStatus::Returned->value)))
                ->exists(),
            'has_stuck_order' => StuckOrderScope::apply($orders(), $days)->exists(),
        ];

        $stored = Customer::query()->whereKey($id)->first(self::FLAGS);

        if ($stored === null) {
            return;
        }

        $customer->forceFill($flags)->syncOriginalAttributes(self::FLAGS);

        if ($stored->only(self::FLAGS) === $flags) {
            return;
        }

        Customer::query()->whereKey($id)->update($flags + ['updated_at' => now()]);
        SafeBroadcast::send(new CustomerUpdated($customer));
    }
}
