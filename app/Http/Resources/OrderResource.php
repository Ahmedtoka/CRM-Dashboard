<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShipmentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $createdBy = $this->created_by_id !== null ? $this->createdBy : null;
        $customer = $this->customer_id !== null ? $this->customer : null;
        $shipment = $this->shipment;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status?->value,
            'type' => $this->type?->value,
            'platform' => $this->platform?->value,
            'conversation_id' => $this->conversation_id,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone] : null,
            'created_by' => $createdBy ? ['id' => $createdBy->id, 'name' => $createdBy->name] : null,
            'subtotal' => (float) $this->subtotal,
            'shipping_fee' => (float) $this->shipping_fee,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'currency' => $this->currency,
            'financial_status' => $this->financial_status,
            'fulfillment_status' => $this->fulfillment_status,
            'invoice_url' => $this->invoice_url,
            'shopify_order_id' => $this->shopify_order_id,
            'shopify_draft_order_id' => $this->shopify_draft_order_id,
            'shipping' => [
                'name' => $this->shipping_name,
                'phone' => $this->shipping_phone,
                'city' => $this->shipping_city,
                'address' => $this->shipping_address,
            ],
            'note' => $this->note,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->items->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'variant_id' => $i->variant_id,
                'title' => $i->title,
                'sku' => $i->sku,
                'qty' => (int) $i->qty,
                'price' => (float) $i->price,
            ])->values()->all(),
            'shipment' => $shipment ? [
                'id' => $shipment->id,
                'carrier' => $shipment->carrier,
                'status' => $shipment->status?->value,
                'tracking_number' => $shipment->tracking_number,
                'last_event_at' => $shipment->last_event_at?->toIso8601String(),
                'events' => $shipment->events->sortBy('occurred_at')->map(fn (ShipmentEvent $e) => [
                    'status' => $e->status?->value,
                    'description' => $e->description,
                    'location' => $e->location,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                ])->values()->all(),
            ] : null,
        ];
    }
}
