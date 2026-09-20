<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One improvement the nightly review proposed (design §6). It changes
 * nothing until a supervisor approves it: `App\Bot\Learning\SuggestionApplier`
 * then writes it and stamps `applied_at`, or leaves the row pending with
 * `error` when it no longer validates.
 */
class BotSuggestion extends Model
{
    public const TYPES = ['script_text', 'new_faq', 'intent_keywords', 'flow_step'];

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'report_id',
        'type',
        'source',
        'target',
        'current',
        'proposed',
        'reason',
        'evidence',
        'status',
        'decided_by_id',
        'decided_at',
        'applied_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'current' => 'array',
            'proposed' => 'array',
            'evidence' => 'array',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BotLearningReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(BotLearningReport::class, 'report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
