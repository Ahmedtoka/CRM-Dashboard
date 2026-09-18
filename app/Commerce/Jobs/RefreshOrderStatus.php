<?php

namespace App\Commerce\Jobs;

use App\Commerce\OrderStatusResolver;
use App\Events\OrderUpdated;
use App\Models\Order;
use App\Support\SafeBroadcast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recomputes an order's stored mismatch after a related change (Shopify
 * mapper, carrier event) and re-broadcasts the order when the verdict moved.
 */
class RefreshOrderStatus implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('commerce');
    }

    public function handle(OrderStatusResolver $resolver): void
    {
        $order = Order::with('shipment.events')->find($this->orderId);

        if ($order === null) {
            return;
        }

        $before = [$order->mismatch, $order->mismatch_reason];
        $fresh = $resolver->refresh($order);

        if ([$fresh->mismatch, $fresh->mismatch_reason] !== $before) {
            SafeBroadcast::send(new OrderUpdated($fresh));
        }
    }
}
