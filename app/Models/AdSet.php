<?php

namespace App\Models;

use Database\Factories\AdSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdSet extends Model
{
    /** @use HasFactory<AdSetFactory> */
    use HasFactory;

    protected $fillable = ['ad_campaign_id', 'external_id', 'name', 'status'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }
}
