<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPlatform extends Model
{
    /** @use HasFactory<\Database\Factories\UserPlatformFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
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
