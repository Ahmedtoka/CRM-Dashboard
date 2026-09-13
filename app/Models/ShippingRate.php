<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRate extends Model
{
    /** @use HasFactory<\Database\Factories\ShippingRateFactory> */
    use HasFactory;

    protected $fillable = [
        'shipping_zone_id',
        'shopify_rate_id',
        'title',
        'price',
        'min_order_subtotal',
        'max_order_subtotal',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'min_order_subtotal' => 'decimal:2',
            'max_order_subtotal' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }
}
