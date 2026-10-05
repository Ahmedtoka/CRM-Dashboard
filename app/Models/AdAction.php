<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A Stop / Run attempt from slice 1. FROZEN 2026-10-07 (Phase B, B1): every row was copied into ad_write_actions
 * (source=legacy) and new writes go through the write pipeline. Read-only history, kept and never dropped.
 */
class AdAction extends Model
{
    protected static function booted(): void
    {
        $frozen = fn () => throw new LogicException('ad_actions is frozen; use ad_write_actions');
        static::creating($frozen);
        static::updating($frozen);
        static::deleting($frozen);
    }

    public const OK = 'ok';

    public const ERROR = 'error';

    protected $fillable = ['user_id', 'platform', 'ad_account_id', 'account_name', 'level', 'external_id', 'name', 'from_status', 'to_status', 'reason', 'result', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }
}
