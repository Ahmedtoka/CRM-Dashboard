<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A public test link the owner shares with the team (design 2026-09-21 §1).
 * The token in `/try/{token}` is the only secret, so it is long and random;
 * a link can be stopped, given an expiry, and capped in sessions and in
 * messages per session.
 */
class BotTestLink extends Model
{
    /** Ambiguity-free alphabet: no O/0/I/l, so a token read off a screen still works. */
    public const TOKEN_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    public const TOKEN_LENGTH = 32;

    public const DEFAULT_MAX_MESSAGES = 60;

    protected $fillable = [
        'token',
        'label',
        'is_active',
        'expires_at',
        'max_sessions',
        'max_messages_per_session',
        'channel_account_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'max_sessions' => 'integer',
            'max_messages_per_session' => 'integer',
            'views_count' => 'integer',
            'sessions_count' => 'integer',
        ];
    }

    public static function newToken(): string
    {
        $alphabet = self::TOKEN_ALPHABET;
        $token = '';

        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $token;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isFull(): bool
    {
        return $this->max_sessions !== null && $this->sessions()->count() >= $this->max_sessions;
    }

    /** Open for a new or continuing session: running, not expired. */
    public function isOpen(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    public function messageCap(): int
    {
        return max(1, (int) ($this->max_messages_per_session ?: self::DEFAULT_MAX_MESSAGES));
    }

    public function url(): string
    {
        return url('/try/'.$this->token);
    }

    public function channelAccountName(): string
    {
        return 'تجربة: '.Str::limit($this->label, 40, '');
    }

    /** @return HasMany<BotTestSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(BotTestSession::class);
    }

    /** @return BelongsTo<ChannelAccount, $this> */
    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
