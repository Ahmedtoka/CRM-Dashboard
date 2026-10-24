<?php

namespace App\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentEvent;
use App\Shopify\Sync\Mappers\Payload;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes the FulfillmentEvent rows (round 2026-10-24) from the three places Shopify tells us about delivery:
 * a `shipment_status` change on the fulfillment itself (webhook or sync), a `fulfillment_events/create` webhook,
 * and the fulfillment's `events` in the GraphQL sync. Every new row that maps to a failed delivery raises the
 * moderator alert (DeliveryFailureAlert) once.
 */
final class FulfillmentEventRecorder
{
    public function __construct(private readonly DeliveryFailureAlert $alert) {}

    /** The fulfillment's own shipment_status moved (or it arrived with one): one row, unless Shopify's events already say so. */
    public function fromStatusChange(Fulfillment $fulfillment, ?string $previous): ?FulfillmentEvent
    {
        $status = Payload::lower($fulfillment->shipment_status);

        if ($status === null || $status === $previous || ShippingTimeline::stepFor($status) === null) {
            return null;
        }

        $at = $fulfillment->shopify_updated_at ?? now();

        // A Shopify event with the same status around the same time already recorded it.
        $known = FulfillmentEvent::query()->where('fulfillment_id', $fulfillment->id)->where('status', $status)
            ->whereBetween('happened_at', [$at->copy()->subMinutes(10), $at->copy()->addMinutes(10)])->exists();

        if ($known) {
            return null;
        }

        return $this->store($fulfillment, [
            'shopify_event_id' => null,
            'status' => $status,
            'message' => null,
            'happened_at' => $at,
            'source' => FulfillmentEvent::SOURCE_FULFILLMENT,
        ]);
    }

    /**
     * A Shopify FulfillmentEvent (REST webhook payload or the GraphQL node already flattened by OrderMapper):
     * id, status, happened_at, message. Idempotent on the Shopify id.
     *
     * @param  array<string, mixed>  $event
     */
    public function fromShopifyEvent(Fulfillment $fulfillment, array $event, string $source = FulfillmentEvent::SOURCE_WEBHOOK): ?FulfillmentEvent
    {
        $status = Payload::lower($event['status'] ?? null);
        $id = Payload::id($event['id'] ?? null);

        if ($status === null || ShippingTimeline::stepFor($status) === null) {
            return null;
        }

        if ($id !== null && FulfillmentEvent::query()->where('shopify_event_id', $id)->exists()) {
            return null;
        }

        $happenedAt = Payload::time($event['happened_at'] ?? $event['happenedAt'] ?? $event['created_at'] ?? null) ?? now();

        $row = $this->store($fulfillment, [
            'shopify_event_id' => $id,
            'status' => $status,
            'message' => Payload::string($event['message'] ?? null) !== null ? mb_substr((string) $event['message'], 0, 500) : null,
            'happened_at' => $happenedAt,
            'source' => $source,
        ]);

        if ($row === null) {
            return null;
        }

        // The event is newer than what the fulfillment row knows: move its shipment status with it.
        $latest = FulfillmentEvent::query()->where('fulfillment_id', $fulfillment->id)->orderByDesc('happened_at')->orderByDesc('id')->first();

        if ($latest !== null && $latest->id === $row->id) {
            $fulfillment->forceFill([
                'shipment_status' => $status,
                'delivered_at' => ShippingTimeline::stepFor($status) === ShipmentStatus::Delivered ? ($fulfillment->delivered_at ?? $happenedAt) : $fulfillment->delivered_at,
            ])->save();
        }

        return $row;
    }

    /** @param  array<string, mixed>  $attributes */
    private function store(Fulfillment $fulfillment, array $attributes): ?FulfillmentEvent
    {
        try {
            $event = FulfillmentEvent::create($attributes + ['fulfillment_id' => $fulfillment->id, 'order_id' => $fulfillment->order_id]);
        } catch (UniqueConstraintViolationException) {
            return null; // the same Shopify event delivered twice at once
        }

        if (ShippingTimeline::stepFor($event->status) === ShipmentStatus::FailedAttempt) {
            rescue(fn () => $this->alert->raise($fulfillment, $event), null, report: true);
        }

        return $event;
    }
}
