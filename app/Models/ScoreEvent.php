<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a moderator's points ledger (queue Part 2). Append-only: a reversal is a new line
 * with the opposite points, the same kind and day, and `reverses_id`; the only update ever made is
 * `reversed_by_id` on the line it cancels. `business_date` is a plain 'Y-m-d' string (Cairo).
 * The reason is a `lang/*\/queue.php` key (`points.{reason_key}`) with its vars, rendered in the
 * viewer's language (ScoreKeeper::reasonText()).
 */
class ScoreEvent extends Model
{
    use HasFactory;

    public const KINDS = ['base', 'speed', 'case', 'review', 'qa', 'no_reply'];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reason_vars' => 'array', 'created_at' => 'datetime', 'points' => 'integer', 'requested' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<QueueEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'queue_entry_id');
    }

    /** @return BelongsTo<SupportCase, $this> */
    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class);
    }

    /** @return BelongsTo<QaReview, $this> */
    public function qa(): BelongsTo
    {
        return $this->belongsTo(QaReview::class, 'qa_review_id');
    }
}
