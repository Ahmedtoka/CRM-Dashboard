<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fulfillment extends Model
{
    /** @use HasFactory<\Database\Factories\FulfillmentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'shopify_fulfillment_id',
        'status',
        'tracking_company',
        'tracking_number',
        'tracking_url',
        'shipment_status',
        'shopify_created_at',
        'shopify_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'shopify_created_at' => 'datetime',
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
}
