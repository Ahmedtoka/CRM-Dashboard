<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a return/exchange case's lifecycle (round 2026-10-24): which stage, when, by whom, with the
 * step's facts in `meta` (pickup date, refund amount, the exchange order, a cancel reason) and whether the
 * customer was told (`customer_notified_at`, else `notify_error`).
 */
class SupportCaseEvent extends Model
{
    protected $fillable = [
        'support_case_id',
        'stage',
        'happened_at',
        'user_id',
        'meta',
        'script_key',
        'customer_notified_at',
        'notify_error',
    ];

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
            'meta' => 'array',
            'customer_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportCase, $this> */
    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
