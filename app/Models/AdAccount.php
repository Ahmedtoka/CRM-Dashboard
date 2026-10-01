<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AdAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class AdAccount extends Model
{
    /** @use HasFactory<AdAccountFactory> */
    use HasFactory;

    protected $fillable = ['connection_id', 'platform', 'external_id', 'name', 'currency', 'timezone', 'status', 'balance', 'is_active', 'last_synced_at'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'is_active' => 'boolean', 'last_synced_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AdPlatformConnection::class, 'connection_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(AdDailyMetric::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AdAccountAssignment::class);
    }

    /** The buyer whose assignment covers the given day (ends_on null = open). */
    public function buyerOn(CarbonInterface|string $date): ?MediaBuyer
    {
        $day = Carbon::parse($date)->toDateString();

        return $this->assignments()
            ->where('starts_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->orderByDesc('starts_on')
            ->first()
            ?->buyer;
    }
}
