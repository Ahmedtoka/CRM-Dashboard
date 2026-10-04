<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory;

    public const STATUSES = ['planned', 'open', 'closed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'settings_snapshot' => 'array'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ShiftMember::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    public function isOpenNow(): bool
    {
        return $this->status === 'open' && $this->starts_at->lte(now()) && $this->ends_at->gt(now());
    }
}
