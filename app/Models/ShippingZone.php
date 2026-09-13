<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingZone extends Model
{
    /** @use HasFactory<\Database\Factories\ShippingZoneFactory> */
    use HasFactory;

    protected $fillable = [
        'shopify_zone_id',
        'name',
        'countries',
    ];

    protected function casts(): array
    {
        return [
            'countries' => 'array',
        ];
    }

    /**
     * @return HasMany<ShippingZoneRegion, $this>
     */
    public function regions(): HasMany
    {
        return $this->hasMany(ShippingZoneRegion::class);
    }

    /**
     * @return HasMany<ShippingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }
}
