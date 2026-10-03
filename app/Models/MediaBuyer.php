<?php

namespace App\Models;

use Database\Factories\MediaBuyerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaBuyer extends Model
{
    /** @use HasFactory<MediaBuyerFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'color', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AdAccountAssignment::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(BuyerTarget::class);
    }
}
