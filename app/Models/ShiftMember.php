<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class ShiftMember extends Model
{
    use HasFactory;

    public const STATUSES = ['available', 'busy', 'pending_break', 'break', 'offline', 'left'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime', 'break_at' => 'datetime', 'break_started_at' => 'datetime', 'break_ends_at' => 'datetime', 'last_heartbeat_at' => 'datetime', 'stats' => 'array'];
    }

    public function shift(): BelongsTo { return $this->belongsTo(Shift::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function openEntries(): HasMany { return $this->hasMany(QueueEntry::class)->whereIn('status', ['called', 'active']); }

    public function cap(): int
    {
        return (int) ($this->windows_cap ?? QueueSetting::current()->windows_per_moderator);
    }
}
