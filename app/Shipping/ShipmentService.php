<?php

namespace App\Shipping;

use App\Analytics\ActivityLogger;
use App\Enums\ActorType;
use App\Enums\ShipmentStatus;
use App\Events\OrderUpdated;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\Order;
use App\Models\Shipment;
use App\Shipping\Contracts\ShippingProvider;
use App\Shopify\Customers\CustomerOrderFlags;
use App\Support\SafeBroadcast;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

class ShipmentService
{
    public function __construct(
        private readonly ShippingProvider $provider,
        private readonly ActivityLogger $logger,
        private readonly OutboundService $outbound,
        private readonly CustomerOrderFlags $customerFlags,
    ) {}

    /**
     * Guarded by a unique index on shipments.order_id: if two concurrent callers
     * (e.g. a duplicate webhook delivery) both decide an order needs a shipment,
     * only the first insert wins and the loser returns the winner's row instead
     * of erroring or creating a second shipment.
     */
    public function createFor(Order $order): Shipment
    {
        $result = $this->provider->createShipment($order);

        try {
            $shipment = $order->shipment()->create([
                'carrier' => $this->provider->name(),
                'tracking_number' => $result->trackingNumber,
                'status' => ShipmentStatus::Created,
                'last_event_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return Shipment::where('order_id', $order->id)->firstOrFail();
        }

        $shipment->events()->create([
            'status' => ShipmentStatus::Created,
            'description' => $result->success ? 'Shipment created' : $result->error,
            'occurred_at' => now(),
        ]);

        return $shipment;
    }

    public function applyEvent(
        Shipment $shipment,
        ShipmentStatus $status,
        ?string $description = null,
        ?string $location = null,
        ?CarbonInterface $at = null,
    ): Shipment {
        $at ??= now();

        $shipment->events()->create([
            'status' => $status,
            'description' => $description,
            'location' => $location,
            'occurred_at' => $at,
        ]);

        $shipment->forceFill([
            'status' => $status,
            'last_event_at' => $at,
        ])->save();

        $order = $shipment->order;

        if ($order?->customer !== null) {
            $this->customerFlags->recompute($order->customer);
        }

        $this->logger->log(
            ActorType::System,
            null,
            ActivityLogger::SHIPMENT_UPDATED,
            $shipment,
            $order?->conversation,
            ['status' => $status->value, 'location' => $location],
        );

        if (config('crm.notify_customer_on_shipment') && in_array($status, [ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered], true)) {
            $this->notifyCustomer($order, $status);
        }

        SafeBroadcast::send(new OrderUpdated($order ?? $shipment->order()->first()));

        return $shipment;
    }

    private function notifyCustomer(?Order $order, ShipmentStatus $status): void
    {
        $conversation = $order?->conversation;

        if ($conversation === null) {
            return;
        }

        $body = $status === ShipmentStatus::Delivered
            ? 'تم توصيل طلبك بنجاح! شكراً لتعاملك معنا 🎉'
            : 'طلبك مع مندوب التوصيل وهيوصلك قريب 🚚';

        try {
            $this->outbound->sendBot($conversation, $body);
        } catch (WindowClosedException) {
            // WhatsApp (or any channel) with a closed/template-only window: skip silently,
            // sendBot() cannot carry a template option.
        }
    }
}
