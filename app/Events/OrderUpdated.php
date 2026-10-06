<?php

namespace App\Events;

use App\Commerce\OrderStatusResolver;
use App\Models\Order;
use App\Support\InboxChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

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
        return [new PrivateChannel('inbox'), ...InboxChannels::forPlatform($this->order->platform)];
    }

    public function broadcastWith(): array
    {
        $o = $this->order;

        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'shopify_order_name' => $o->shopify_order_name,
            'status' => $o->status?->value,
            'type' => $o->type?->value,
            'total' => $o->total,
            'invoice_url' => $o->invoice_url,
            'conversation_id' => $o->conversation_id,
            'customer_id' => $o->customer_id,
            'shipment' => null, // fresh-orders F4: no CRM shipment; Shopify's shipment_status below
            'created_at' => $o->created_at?->toIso8601String(),
            // Lets an open list patch its row in place after a Shopify refresh (spec §3.2).
            'financial_status' => $o->financial_status,
            'fulfillment_status' => $o->fulfillment_status,
            'shipment_status' => $o->shipment_status,
            // Reverb frames are capped at 10 KB: a long Shopify note must not drop the whole event.
            'note' => $o->note === null ? null : Str::limit($o->note, 500),
            'shopify_updated_at' => $o->shopify_updated_at?->toIso8601String(),
            'last_synced_at' => $o->last_synced_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
            'is_final' => $o->isFinalForSync(),
            // A refresh can raise or clear a mismatch; the row shows it without a reload.
            'mismatch' => (bool) $o->mismatch,
            'mismatch_reason' => $o->mismatch_reason,
            // The same resolved payment / fulfilment / carrier step the OrderResource carries.
            'display' => app(OrderStatusResolver::class)->resolve($o)->toArray(),
        ];
    }
}
