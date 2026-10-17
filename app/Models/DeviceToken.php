<?php

namespace App\Models;

use Database\Factories\DeviceTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A phone registered for push (FCM). Looked up by token_hash, never by the raw token.
 */
class DeviceToken extends Model
{
    /** @use HasFactory<DeviceTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'platform',
        'token',
        'token_hash',
        'app_version',
        'locale',
        'last_registered_at',
        'last_push_at',
        'failure_count',
        'disabled_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'last_registered_at' => 'datetime',
            'last_push_at' => 'datetime',
            'disabled_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    /** The lookup key of a raw push token (sha256 hex, 64 chars). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Devices that still receive pushes.
     *
     * @param  Builder<DeviceToken>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Sanctum token that registered this device (null for rows not tied to a login).
     *
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
