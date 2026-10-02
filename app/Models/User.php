<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'locale',
        'is_active',
        'color',
        'last_seen_at',
        'preferences',
    ];

    /**
     * Notification preferences (spec §5.4, Dashboard Experience Task 14):
     * sound, desktop notifications, whose messages to notify about, and the
     * sound volume. Stored sparse in `preferences` and merged with these
     * defaults by `notificationPreferences()`.
     */
    public const DEFAULT_PREFERENCES = ['sound' => true, 'desktop_notifications' => false, 'notify_scope' => 'all_visible', 'sound_volume' => 0.6];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    /** Notification preferences merged with defaults; unknown keys in `preferences` are ignored. */
    public function notificationPreferences(): array
    {
        return array_replace(self::DEFAULT_PREFERENCES, array_intersect_key($this->preferences ?? [], self::DEFAULT_PREFERENCES));
    }

    /**
     * @return HasMany<UserNotification, $this>
     */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    /**
     * @return HasMany<UserPlatform, $this>
     */
    public function userPlatforms(): HasMany
    {
        return $this->hasMany(UserPlatform::class);
    }

    /**
     * @return array<int, Platform>
     */
    public function platforms(): array
    {
        return $this->userPlatforms->map(fn (UserPlatform $up) => $up->platform)->all();
    }

    public function canAccessPlatform(Platform $platform): bool
    {
        // Ads Hub roles never see inbox data, whatever platform rows a past role left behind.
        if ($this->isAdsRole()) {
            return false;
        }

        if ($this->isSupervisorOrAbove()) {
            return true;
        }

        return in_array($platform, $this->platforms(), true);
    }

    /** Admins, supervisors and moderators: the people who work the inbox (never the Ads Hub roles). */
    public function scopeInboxStaff(Builder $query): Builder
    {
        return $query->whereIn('role', [UserRole::Admin->value, UserRole::Supervisor->value, UserRole::Moderator->value]);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** The Ads Hub roles: they live in /ads and never in the inbox, the queue or the board. */
    public function isAdsRole(): bool
    {
        return in_array($this->role, [UserRole::MediaBuyer, UserRole::Content], true);
    }

    public function isInboxStaff(): bool
    {
        return ! $this->isAdsRole();
    }

    public function isSupervisorOrAbove(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Supervisor], true);
    }
}
