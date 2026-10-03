<?php

namespace App\Models;

use Database\Factories\AdPlatformConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdPlatformConnection extends Model
{
    /** @use HasFactory<AdPlatformConnectionFactory> */
    use HasFactory;

    protected $fillable = ['platform', 'name', 'credentials', 'status', 'last_error', 'last_synced_at'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'last_synced_at' => 'datetime'];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(AdAccount::class, 'connection_id');
    }
}
