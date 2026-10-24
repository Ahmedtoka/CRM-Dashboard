<?php

namespace App\Shipping;

use App\Commerce\ShopifyDeliveryStep;
use App\Enums\ShipmentStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use Carbon\CarbonInterface;

/**
 * The order's shipping timeline, from Shopify only (round 2026-10-24; the owner: no courier API). Built from the
 * order's fulfillments and their FulfillmentEvents: one «created» step per fulfillment (carrier, tracking), then
 * every status Shopify reported, mapped to the CRM's steps (ShipmentStatus) and de-duplicated when a step repeats.
 *
 * Read by the order page and the thread card (OrderResource `shipping`), and by the bot's «فين أوردري» answer
 * through Order::shippingSummary() (docs/round-2026-10-24/orders-api.md).
 */
final class ShippingTimeline
{
    /**
     * @return list<array{step:string, status:?string, at:string, fulfillment_id:int, message:?string, tracking_company:?string, tracking_number:?string, tracking_url:?string}>
     */
    public static function build(Order $order): array
    {
        $order->loadMissing(['fulfillments', 'fulfillmentEvents']);
        $entries = [];

        foreach ($order->fulfillments as $f) {
            $entries[] = self::entry($f, ShipmentStatus::Created, null, $f->shopify_created_at ?? $f->created_at, null);

            if (in_array($f->status, ['cancelled', 'failure', 'error'], true)) {
                $entries[] = self::entry($f, ShipmentStatus::Cancelled, $f->status, $f->shopify_updated_at ?? $f->updated_at, null);

                continue;
            }

            $events = $order->fulfillmentEvents->where('fulfillment_id', $f->id)->sortBy([['happened_at', 'asc'], ['id', 'asc']]);

            foreach ($events as $e) {
                $step = self::stepFor($e->status);

                if ($step === null || $step === ShipmentStatus::Created) {
                    continue; // «confirmed» / label steps add nothing to «created»
                }

                $entries[] = self::entry($f, $step, $e->status, $e->happened_at, $e->message);
            }

            // A delivery known only from the fulfillment row (an import before events were recorded).
            if ($f->delivered_at !== null && ! collect($entries)->contains(fn ($e) => $e['fulfillment_id'] === $f->id && $e['step'] === ShipmentStatus::Delivered->value)) {
                $entries[] = self::entry($f, ShipmentStatus::Delivered, 'delivered', $f->delivered_at, null);
            } elseif ($events->isEmpty() && ($step = self::stepFor($f->shipment_status)) !== null && $step !== ShipmentStatus::Created) {
                $entries[] = self::entry($f, $step, $f->shipment_status, $f->shopify_updated_at ?? $f->updated_at, null);
            }
        }

        usort($entries, fn ($a, $b) => [$a['ts'], $a['order']] <=> [$b['ts'], $b['order']]);

        // The same step twice in a row says nothing new (two «in transit» scans).
        $out = [];
        foreach ($entries as $e) {
            $last = $out === [] ? null : $out[array_key_last($out)];
            if ($last !== null && $last['fulfillment_id'] === $e['fulfillment_id'] && $last['step'] === $e['step']) {
                continue;
            }
            unset($e['ts'], $e['order']);
            $out[] = $e;
        }

        return $out;
    }

    /**
     * Where the order stands now, for the bot and the cards. `failed_attempt` is true when the latest step is a
     * failed delivery (the moderator was alerted, DeliveryFailureAlert). `carrier_updates` says whether Shopify
     * ever reported a step after «created» for this order: Le Voile's courier does not push tracking into Shopify
     * today, so most orders only ever show «created» (round 2026-10-24 audit of the live copy).
     *
     * @return array{step:?string, step_at:?string, failed_attempt:bool, delivered_at:?string, carrier_updates:bool, tracking:array{company:?string, number:?string, url:?string}|null, timeline:list<array<string, mixed>>}
     */
    public static function summary(Order $order): array
    {
        $timeline = self::build($order);
        $last = $timeline === [] ? null : $timeline[array_key_last($timeline)];
        $step = $order->delivered_at !== null ? ShipmentStatus::Delivered->value : ($last['step'] ?? ShopifyDeliveryStep::of($order)?->value);
        $tracking = $order->fulfillments->sortByDesc('shopify_created_at')->first(fn (Fulfillment $f) => filled($f->tracking_number) || filled($f->tracking_url));

        return [
            'step' => $step,
            'step_at' => $order->delivered_at?->toIso8601String() ?? $last['at'] ?? null,
            'failed_attempt' => $step === ShipmentStatus::FailedAttempt->value,
            'delivered_at' => ($order->delivered_at ?? $order->fulfillments->max('delivered_at'))?->toIso8601String(),
            'carrier_updates' => collect($timeline)->contains(fn ($e) => $e['step'] !== ShipmentStatus::Created->value && $e['step'] !== ShipmentStatus::Cancelled->value),
            'tracking' => $tracking === null ? null : [
                'company' => $tracking->tracking_company,
                'number' => $tracking->tracking_number,
                'url' => is_string($tracking->tracking_url) && preg_match('#^https?://#i', $tracking->tracking_url) ? $tracking->tracking_url : null,
            ],
            'timeline' => $timeline,
        ];
    }

    /** Shopify's status word (REST shipment_status, GraphQL display/event status, any case) → the CRM step. */
    public static function stepFor(?string $status): ?ShipmentStatus
    {
        return ShopifyDeliveryStep::stepFor($status);
    }

    private static function entry(Fulfillment $f, ShipmentStatus $step, ?string $status, ?CarbonInterface $at, ?string $message): array
    {
        $at ??= $f->created_at ?? now();

        return [
            'ts' => $at->valueOf(),
            'order' => $step === ShipmentStatus::Created ? 0 : 1,
            'step' => $step->value,
            'status' => $status,
            'at' => $at->toIso8601String(),
            'fulfillment_id' => $f->id,
            'message' => $message,
            'tracking_company' => $f->tracking_company,
            'tracking_number' => $f->tracking_number,
            'tracking_url' => is_string($f->tracking_url) && preg_match('#^https?://#i', $f->tracking_url) ? $f->tracking_url : null,
        ];
    }
}
