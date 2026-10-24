<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery status Shopify reported for a fulfillment (round 2026-10-24): the raw Shopify word in `status`
 * (`App\Shipping\ShippingTimeline` maps it to the CRM's step), when it happened, and where it came from.
 */
class FulfillmentEvent extends Model
{
    public const SOURCE_WEBHOOK = 'webhook_event';

    public const SOURCE_FULFILLMENT = 'fulfillment';

    public const SOURCE_SYNC = 'sync';

    protected $fillable = [
        'fulfillment_id',
        'order_id',
        'shopify_event_id',
        'status',
        'message',
        'happened_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Fulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScope(Order::LOAD_TEST_SCOPE);
    }
}
