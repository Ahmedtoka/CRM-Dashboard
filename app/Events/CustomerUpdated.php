<?php

namespace App\Events;

use App\Models\Customer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatch through App\Support\SafeBroadcast.
 */
class CustomerUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Customer $customer) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inbox')];
    }

    public function broadcastWith(): array
    {
        $c = $this->customer;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'shopify_customer_id' => $c->shopify_customer_id,
            'is_repeat' => (bool) $c->is_repeat,
            'has_open_order' => (bool) $c->has_open_order,
            'has_return' => (bool) $c->has_return,
            'has_stuck_order' => (bool) $c->has_stuck_order,
        ];
    }
}
