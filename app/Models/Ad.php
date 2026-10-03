<?php

namespace App\Models;

use Database\Factories\AdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ad extends Model
{
    /** @use HasFactory<AdFactory> */
    use HasFactory;

    protected $fillable = ['ad_account_id', 'ad_campaign_id', 'ad_set_id', 'external_id', 'name', 'status', 'effective_status', 'type', 'headline', 'body', 'thumbnail_url', 'image_url', 'video_url', 'preview_url', 'preview_html', 'permalink_url', 'instagram_permalink_url', 'object_story_id', 'carousel', 'url_tags', 'created_time', 'media_fetched_at', 'raw'];

    protected function casts(): array
    {
        return ['carousel' => 'array', 'raw' => 'array', 'created_time' => 'datetime', 'media_fetched_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class, 'ad_set_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(AdDailyMetric::class);
    }

    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(AdMaterial::class, 'ad_material_ads', 'ad_id', 'ad_material_id');
    }
}
