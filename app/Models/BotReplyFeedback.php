<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «رد البوت ده غلط» (b2, 2026-10-19): a staff member's verdict on one bot message.
 */
class BotReplyFeedback extends Model
{
    protected $table = 'bot_reply_feedback';

    /** The reason chips, in the order staff see them. */
    public const REASONS = ['misunderstood', 'wrong_info', 'should_handover', 'needless_handover', 'repeated', 'slow', 'tone', 'other'];

    public const STATUSES = ['open', 'converted', 'dismissed'];

    /** What the admin can turn an item into (a pending learning suggestion each). */
    public const CONVERSIONS = ['knowledge', 'instruction', 'eval'];

    protected $fillable = [
        'message_id', 'conversation_id', 'bot_run_id', 'user_id', 'reasons', 'note', 'correct_reply',
        'tier', 'layer', 'status', 'converted_to', 'suggestion_id', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BotRun, $this> */
    public function botRun(): BelongsTo
    {
        return $this->belongsTo(BotRun::class);
    }
}
