<?php

namespace App\Models;

use Database\Factories\FulfillmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fulfillment extends Model
{
    /** @use HasFactory<FulfillmentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'shopify_fulfillment_id',
        'status',
        'tracking_company',
        'tracking_number',
        'tracking_url',
        'shipment_status',
        'delivered_at',
        'shopify_created_at',
        'shopify_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_created_at' => 'datetime',
            'delivered_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Every delivery status Shopify reported for this fulfillment, oldest first (round 2026-10-24). @return HasMany<FulfillmentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FulfillmentEvent::class)->orderBy('happened_at')->orderBy('id');
    }
}
