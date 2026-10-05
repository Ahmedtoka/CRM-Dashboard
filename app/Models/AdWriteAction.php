<?php

namespace App\Models;

use Database\Factories\AdWriteActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * One human write intent on an ad platform (Phase B write pipeline): proposed, confirmed, executed, finished.
 * Rows are history and are never deleted.
 */
class AdWriteAction extends Model
{
    /** @use HasFactory<AdWriteActionFactory> */
    use HasFactory;

    public const PROPOSED = 'proposed';

    public const EXECUTING = 'executing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const SUPERSEDED = 'superseded';

    public const SUPERSEDED_BY_STOP = 'superseded_by_stop';

    public const ROLLED_BACK = 'rolled_back';

    public const TERMINAL = [self::SUCCEEDED, self::FAILED, self::EXPIRED, self::CANCELLED, self::SUPERSEDED, self::SUPERSEDED_BY_STOP, self::ROLLED_BACK];

    /** Confirmed and not finished: the platform may be changing. */
    public const OPEN = [self::EXECUTING, self::UNKNOWN];

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'diff' => 'array',
            'expected' => 'array',
            'limits_checked' => 'array',
            'notes' => 'array',
            'outcome' => 'array',
            'attempts' => 'integer',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
            'executing_at' => 'datetime',
            'finished_at' => 'datetime',
            'retry_at' => 'datetime',
            'restart_lock_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->public_id ??= (string) Str::ulid();
        });
        static::deleting(function () {
            throw new LogicException('Ad write actions are history and are never deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isStop(): bool
    {
        return $this->to_status === 'paused';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AdWriteStep::class)->orderBy('seq');
    }
}
