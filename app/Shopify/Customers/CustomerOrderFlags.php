<?php

namespace App\Shopify\Customers;

use App\Commerce\StuckOrderScope;
use App\Enums\OrderStatus;
use App\Events\CustomerUpdated;
use App\Models\Customer;
use App\Models\Order;
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

    /**
     * $broadcast is false for a bulk import: thousands of historical orders must
     * not push one realtime update per customer (each is a synchronous call to
     * the broadcast server, seconds apiece when it is unreachable).
     */
    public function recompute(Customer $customer, bool $broadcast = true): void
    {
        $id = $customer->getKey();
        $orders = fn (): Builder => Order::query()->where('customer_id', $id);
        $notCancelled = [OrderStatus::Cancelled->value, OrderStatus::Failed->value];

        $shopifyCount = (int) Customer::query()->whereKey($id)->value('shopify_orders_count');
        $localCount = $orders()->whereNotIn('status', $notCancelled)->count();

        $days = (int) ($this->integrations->current()?->settingsWithDefaults()['stuck_order_days'] ?? 5);

        $flags = [
            'is_repeat' => max($shopifyCount, $localCount) >= 2,
            'has_open_order' => $orders()
                ->whereIn('status', [OrderStatus::Submitting->value, OrderStatus::AwaitingPayment->value, OrderStatus::Confirmed->value])
                ->whereNot(fn (Builder $q) => $q->deliveredOnShopify())
                ->exists(),
            'has_return' => $orders()->whereHas('refunds')->exists(),
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

        if ($broadcast) {
            SafeBroadcast::send(new CustomerUpdated($customer));
        }
    }
}
