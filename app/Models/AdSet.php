<?php

namespace App\Models;

use Database\Factories\AdSetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdSet extends Model
{
    /** @use HasFactory<AdSetFactory> */
    use HasFactory;

    protected $fillable = ['ad_campaign_id', 'external_id', 'name', 'status', 'open_for_drafts_at', 'open_for_drafts_by_id'];

    protected function casts(): array
    {
        return ['open_for_drafts_at' => 'datetime'];
    }

    /** Open slots: ad sets content may target (spec 3.2). */
    public function scopeOpenForDrafts(Builder $q): Builder
    {
        return $q->whereNotNull('ad_sets.open_for_drafts_at');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'open_for_drafts_by_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }
}
