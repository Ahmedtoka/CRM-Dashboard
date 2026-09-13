<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
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
    ];

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
        ];
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
        if ($this->isSupervisorOrAbove()) {
            return true;
        }

        return in_array($platform, $this->platforms(), true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isSupervisorOrAbove(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Supervisor], true);
    }
}
