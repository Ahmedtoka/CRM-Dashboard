<?php

namespace App\Models;

use Database\Factories\ShippingZoneRegionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingZoneRegion extends Model
{
    /** @use HasFactory<ShippingZoneRegionFactory> */
    use HasFactory;

    protected $fillable = [
        'shipping_zone_id',
        'country_code',
        'province_code',
        'province_name',
    ];

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }
}
