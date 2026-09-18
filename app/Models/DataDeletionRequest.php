<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A data deletion request received through Meta's Data Deletion Callback.
 * Only the platform-scoped user id is stored — never a name or message.
 */
class DataDeletionRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NOT_FOUND = 'not_found';

    protected $fillable = [
        'confirmation_code',
        'platform',
        'external_user_id',
        'status',
        'requested_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** A random, unguessable code not used by another request. */
    public static function newConfirmationCode(): string
    {
        do {
            $code = Str::upper(Str::random(16));
        } while (static::where('confirmation_code', $code)->exists());

        return $code;
    }
}
