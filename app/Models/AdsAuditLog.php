<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit trail of ads control-plane and operator actions.
 * Deliberately NOT covered by `crm:prune-retention`: rows are never updated or deleted.
 */
class AdsAuditLog extends Model
{
    protected $table = 'ads_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'before' => 'array', 'after' => 'array', 'meta' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ads_audit_log is append-only.'));
        static::deleting(fn () => throw new LogicException('ads_audit_log is append-only.'));
    }
}
