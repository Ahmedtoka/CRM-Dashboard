<?php

namespace App\Models;

use App\Ads\Launch\LaunchState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One launch draft: one material into one ad set; N ads = files x captions (ad_publications). Moves only via LaunchService. */
class AdLaunch extends Model
{
    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'state' => LaunchState::class, 'hold_from_state' => LaunchState::class,
            'identity' => 'array', 'file_ids' => 'array', 'captions' => 'array', 'original' => 'array', 'checks' => 'array',
            'revision' => 'integer', 'self_approved' => 'boolean',
            'submitted_at' => 'datetime', 'forwarded_at' => 'datetime', 'awaiting_at' => 'datetime', 'decided_at' => 'datetime',
            'approved_at' => 'datetime', 'expires_at' => 'datetime', 'expiring_notified_at' => 'datetime', 'live_at' => 'datetime',
            'stopped_at' => 'datetime', 'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $l) {
            $l->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(AdMaterial::class, 'ad_material_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class, 'ad_set_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(MediaBuyer::class, 'reviewer_buyer_id');
    }

    public function forwarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(AdPublication::class, 'ad_launch_id')->orderBy('id');
    }

    /** files x captions: the ads this launch makes. */
    public function adsCount(): int
    {
        return count((array) $this->file_ids) * count((array) $this->captions);
    }
}
