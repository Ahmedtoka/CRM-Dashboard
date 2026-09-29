<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftMember extends Model
{
    use HasFactory;

    public const STATUSES = ['available', 'busy', 'pending_break', 'break', 'offline', 'left'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime', 'break_at' => 'datetime', 'break_started_at' => 'datetime', 'break_ends_at' => 'datetime', 'last_heartbeat_at' => 'datetime', 'not_arrived_alerted_at' => 'datetime', 'stats' => 'array'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function openEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class)->whereIn('status', ['called', 'active']);
    }

    /** The settings' default number of windows, read once per model instance (the tick and the router ask in loops). */
    private ?int $defaultCap = null;

    /** Her windows: her own cap, else the settings' default (pass the settings when the caller already holds them). */
    public function cap(?QueueSetting $settings = null): int
    {
        if ($this->windows_cap !== null) {
            return (int) $this->windows_cap;
        }

        return $this->defaultCap ??= (int) ($settings ?? QueueSetting::current())->windows_per_moderator;
    }
}
