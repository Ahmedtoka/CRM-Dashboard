<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One platform mutation of an AdWriteAction (before/after, attempts, outcome). */
class AdWriteStep extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'meta' => 'array',
            'seq' => 'integer',
            'attempts' => 'integer',
            'request_sent_at' => 'datetime',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AdWriteAction::class, 'ad_write_action_id');
    }
}
