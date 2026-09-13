<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Order $order) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inbox')];
    }

    public function broadcastWith(): array
    {
        $o = $this->order;
        $shipment = $o->relationLoaded('shipment') ? $o->shipment : $o->shipment()->first();

        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status?->value,
            'type' => $o->type?->value,
            'total' => $o->total,
            'invoice_url' => $o->invoice_url,
            'conversation_id' => $o->conversation_id,
            'customer_id' => $o->customer_id,
            'shipment' => $shipment ? [
                'status' => $shipment->status?->value,
                'tracking_number' => $shipment->tracking_number,
            ] : null,
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }
}
