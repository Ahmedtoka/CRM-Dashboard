<?php

namespace App\Models;

use Database\Factories\UserNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persisted per-user notification (handover / mention). Broadcast in
 * real time via UserNotified, but always readable from `/notifications`
 * too — the bell, sound and desktop notification are all driven by this
 * row surviving a refresh/poll, not just the transient broadcast.
 */
class UserNotification extends Model
{
    /** @use HasFactory<UserNotificationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
