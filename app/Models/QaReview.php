<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The leader's quality check of one closed window (queue Part 2): a 1–5 score, five yes/no items
 * and a note. One per window; a re-score updates it and replaces its points line.
 */
class QaReview extends Model
{
    use HasFactory;

    public const CHECKLIST = ['introduced', 'accurate', 'tone', 'right_reason', 'followed_up'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'score' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<QueueEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'queue_entry_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
