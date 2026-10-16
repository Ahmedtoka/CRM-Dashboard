<?php

namespace App\Confirmations\Listeners;

use App\Confirmations\Events\ShopifyOrderCreated;
use App\Confirmations\OrderConfirmations;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Off the webhook's path: creates the confirmation row (scheduled or skipped) for a new Shopify order. */
final class ScheduleOrderConfirmation implements ShouldQueue
{
    public string $queue = 'commerce';

    public int $tries = 3;

    public function __construct(private readonly OrderConfirmations $confirmations) {}

    public function handle(ShopifyOrderCreated $event): void
    {
        // withLoadTest: a load-test order is recorded as skipped instead of silently ignored.
        $order = Order::withLoadTest()->find($event->orderId);

        if ($order !== null) {
            $this->confirmations->onOrderCreated($order, $event->test, $event->sourceName);
        }
    }
}
