<?php

namespace App\Models;

use Database\Factories\BotFlowVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a `BotFlow` definition (flow designer, Task 1): `draft`
 * (at most one per flow), `published` (at most one — the live definition
 * mirrored into `bot_flows.definition`) or `archived` (a past published
 * version, kept for restore). See `App\Bot\Flows\FlowDrafts`.
 */
class BotFlowVersion extends Model
{
    /** @use HasFactory<BotFlowVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'bot_flow_id',
        'version',
        'status',
        'definition',
        'note',
        'created_by_id',
        'published_by_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(BotFlow::class, 'bot_flow_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }
}
