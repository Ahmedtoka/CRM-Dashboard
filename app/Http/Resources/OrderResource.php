<?php

namespace App\Http\Resources;

use App\Analytics\ActivityLogger;
use App\Commerce\OrderStatusResolver;
use App\Models\ActivityLog;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\ShipmentEvent;
use App\Shipping\ShipmentService;
use App\Shopify\Connection\IntegrationRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Str;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** CRM activity actions shown on the order timeline. */
    private const TIMELINE_ACTIONS = [ActivityLogger::ORDER_CREATED, ActivityLogger::ORDER_RETRIED, ActivityLogger::ORDER_CANCELLED];

    /** Relation name used to hand preloaded timeline logs to each resource in a collection. */
    private const LOGS_RELATION = 'timelineActivityLogs';

    /**
     * Lists preload fulfillments, refunds, shipment events and timeline logs in a
     * fixed number of queries instead of several per order.
     */
    public static function collection($resource)
    {
        $models = $resource instanceof AbstractPaginator ? $resource->getCollection() : $resource;

        if ($models instanceof EloquentCollection && $models->isNotEmpty() && $models->first() instanceof Order) {
            $models->loadMissing(['fulfillments', 'refunds', 'shipment.events']);

            $logs = self::timelineLogs($models->modelKeys())->groupBy('subject_id');
            $models->each(fn (Order $o) => $o->setRelation(self::LOGS_RELATION, $logs->get($o->id, new EloquentCollection)));
        }

        return parent::collection($resource);
    }

    public function toArray(Request $request): array
    {
        $createdBy = $this->created_by_id !== null ? $this->createdBy : null;
        $customer = $this->customer_id !== null ? $this->customer : null;
        $shipment = $this->shipment;
        $display = app(OrderStatusResolver::class)->resolve($this->resource);

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status?->value,
            'type' => $this->type?->value,
            'source' => $this->source?->value,
            'platform' => $this->platform?->value,
            'conversation_id' => $this->conversation_id,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone] : null,
            'created_by' => $createdBy ? ['id' => $createdBy->id, 'name' => $createdBy->name] : null,
            'subtotal' => (float) $this->subtotal,
            'shipping_fee' => (float) $this->shipping_fee,
            'discount' => (float) $this->discount,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'total' => (float) $this->total,
            'currency' => $this->currency,
            'financial_status' => $this->financial_status,
            'fulfillment_status' => $this->fulfillment_status,
            'display' => $display->toArray(),
            'mismatch' => (bool) $this->mismatch,
            'mismatch_reason' => $this->mismatch_reason,
            'invoice_url' => $this->invoice_url,
            'shopify_order_id' => $this->shopify_order_id,
            'shopify_draft_order_id' => $this->shopify_draft_order_id,
            'shopify_admin_url' => $this->shopifyAdminUrl($request),
            'shipping' => [
                'name' => $this->shipping_name,
                'phone' => $this->shipping_phone,
                'city' => $this->shipping_city,
                'address' => $this->shipping_address,
            ],
            'shipping_province_code' => $this->shipping_province_code,
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
                'image_url' => $i->image_url,
            ])->values()->all(),
            'shipment' => $shipment ? [
                'id' => $shipment->id,
                'carrier' => $shipment->carrier,
                'status' => $shipment->status?->value,
                'tracking_number' => $shipment->tracking_number,
                'last_event_at' => $shipment->last_event_at?->toIso8601String(),
                'events' => $shipment->events->sortBy('occurred_at')->map(fn (ShipmentEvent $e) => [
                    'status' => $e->status?->value,
                    'description' => self::eventDescription($e->description),
                    'location' => $e->location,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                ])->values()->all(),
            ] : null,
            'fulfillments' => $this->fulfillments->sortBy('shopify_created_at')->map(fn (Fulfillment $f) => [
                'id' => $f->id,
                'status' => $f->status,
                'shipment_status' => $f->shipment_status,
                'tracking_company' => $f->tracking_company,
                'tracking_number' => $f->tracking_number,
                'tracking_url' => $f->tracking_url,
                'created_at' => $f->shopify_created_at?->toIso8601String(),
                'updated_at' => $f->shopify_updated_at?->toIso8601String(),
            ])->values()->all(),
            'refunds' => $this->refunds->sortBy('shopify_created_at')->map(fn (Refund $r) => [
                'id' => $r->id,
                'amount' => (float) $r->amount,
                'note' => $r->note,
                'restock' => (bool) $r->restock,
                'created_at' => ($r->shopify_created_at ?? $r->created_at)?->toIso8601String(),
            ])->values()->all(),
            'timeline' => $this->timeline(),
        ];
    }

    /**
     * ShipmentTimeline.vue renders `shipment.events[].description` raw — unlike the
     * `timeline` below it carries no `key`/`label_params`, so the CRM's own two
     * sentinels are mapped to the viewer's language here. Carrier text (and any
     * older row) is passed through unchanged.
     */
    private static function eventDescription(?string $stored): ?string
    {
        return match ($stored) {
            ShipmentService::EVENT_CREATED => __('labels.shipment_event.created'),
            ShipmentService::EVENT_ORDER_CANCELLED => __('labels.shipment_event.order_cancelled'),
            default => $stored,
        };
    }

    /**
     * Unified, ascending timeline. `label_params` only ever carry non-secret
     * display values (never raw activity meta or carrier error text).
     *
     * @return list<array{at: string, source: string, key: string, label_params: array}>
     */
    private function timeline(): array
    {
        $entries = [];
        $add = function (?CarbonInterface $at, string $source, string $key, array $params = []) use (&$entries) {
            if ($at === null) {
                return;
            }

            $entries[] = [
                'ts' => $at->valueOf(),
                'at' => $at->toIso8601String(),
                'source' => $source,
                'key' => $key,
                'label_params' => array_filter($params, fn ($v) => $v !== null && $v !== ''),
            ];
        };

        $order = $this->resource;
        $linked = $order->shopify_order_id !== null;

        if ($order->source?->value === 'store') {
            $add($order->created_at, 'shopify', 'order.created', ['name' => $order->shopify_order_name]);
        }

        $add($order->paid_at, $linked ? 'shopify' : 'crm', 'order.paid');

        if ($linked) {
            $add($order->cancelled_at, 'shopify', 'order.cancelled', ['reason' => $order->cancel_reason]);
        }

        foreach ($order->fulfillments as $f) {
            $add($f->shopify_created_at, 'shopify', 'fulfillment.created', [
                'status' => $f->status,
                'tracking_company' => $f->tracking_company,
                'tracking_number' => $f->tracking_number,
                'tracking_url' => $f->tracking_url,
            ]);

            if ($f->shopify_updated_at !== null && ! $f->shopify_updated_at->equalTo($f->shopify_created_at)) {
                $add($f->shopify_updated_at, 'shopify', 'fulfillment.updated', [
                    'status' => $f->status,
                    'shipment_status' => $f->shipment_status,
                ]);
            }
        }

        foreach ($order->refunds as $r) {
            $add($r->shopify_created_at ?? $r->created_at, 'shopify', 'refund.created', [
                'amount' => (float) $r->amount,
                'currency' => $order->currency,
            ]);
        }

        foreach ($order->shipment?->events ?? [] as $e) {
            $add($e->occurred_at, 'shipping', 'shipment.'.$e->status?->value, ['location' => $e->location]);
        }

        $logs = $order->relationLoaded(self::LOGS_RELATION)
            ? $order->getRelation(self::LOGS_RELATION)
            : self::timelineLogs([$order->id]);

        foreach ($logs as $log) {
            $add($log->created_at, 'crm', $log->action, ['user' => $log->user?->name]);
        }

        usort($entries, fn (array $a, array $b) => $a['ts'] <=> $b['ts']);

        return array_map(function (array $e) {
            unset($e['ts']);

            return $e;
        }, $entries);
    }

    /**
     * @param  array<int, int>  $orderIds
     * @return EloquentCollection<int, ActivityLog>
     */
    private static function timelineLogs(array $orderIds): EloquentCollection
    {
        return ActivityLog::query()
            ->whereIn('action', self::TIMELINE_ACTIONS)
            ->where('subject_type', (new Order)->getMorphClass())
            ->whereIn('subject_id', $orderIds)
            ->with('user:id,name')
            ->orderBy('id')
            ->get(['id', 'user_id', 'action', 'subject_id', 'created_at']);
    }

    /**
     * `https://admin.shopify.com/store/{handle}/orders/{id}`; null when the order
     * is not on Shopify or no integration exists. The handle is cached on the
     * request once found so lists don't re-query it per order.
     */
    private function shopifyAdminUrl(Request $request): ?string
    {
        if (blank($this->shopify_order_id)) {
            return null;
        }

        // '' is the cached "no integration" sentinel, so a list queries it once.
        if (! $request->attributes->has('shopify_store_handle')) {
            $domain = app(IntegrationRepository::class)->current()?->shop_domain;

            $request->attributes->set('shopify_store_handle', blank($domain)
                ? ''
                : Str::before(strtolower((string) $domain), '.myshopify.com'));
        }

        $handle = (string) $request->attributes->get('shopify_store_handle');

        return $handle === '' ? null : "https://admin.shopify.com/store/{$handle}/orders/{$this->shopify_order_id}";
    }
}
