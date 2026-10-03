<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueEntry extends Model
{
    use HasFactory;

    public const KINDS = ['inquiry', 'problem', 'case', 'unknown'];

    public const PRIORITIES = ['returning', 'escalation', 'live', 'overnight', 'manual'];

    public const STATUSES = ['waiting', 'called', 'active', 'closed', 'abandoned', 'cancelled'];

    public const CLOSE_REASONS = ['inquiry', 'problem', 'case', 'auto', 'escalation', 'transfer', 'resolved_elsewhere', 'cancelled', 'no_reply'];

    public const OPEN_STATUSES = ['called', 'active'];

    public const TERMINAL_STATUSES = ['closed', 'abandoned', 'cancelled'];

    /** Closes a moderator really handled (average handle time); a transfer / cancel / resolve elsewhere is not one. */
    public const HANDLED_REASONS = ['inquiry', 'problem', 'case', 'auto'];

    /** Manual closes that wait `close_confirm_minutes` before they stand. */
    public const CONFIRMABLE_REASONS = ['inquiry', 'problem'];

    /**
     * Spec 2026-09-30 §2: the final close — her «خلصت», or a supervisor's on her behalf — ends with
     * `queue_closed_thanks` and (addendum C2, the R1 override) hands the conversation back to the bot.
     */
    public const FINAL_CLOSE_REASONS = ['inquiry', 'problem', 'case'];

    /** Spec 2026-09-30 §3: the final closes that get the rating request (a case is rated when it is resolved, Part 2). */
    public const RATED_CLOSE_REASONS = ['inquiry', 'problem'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date', 'enqueued_at' => 'datetime', 'called_at' => 'datetime', 'delivered_at' => 'datetime', 'first_reply_at' => 'datetime',
            'closed_at' => 'datetime', 'last_customer_message_at' => 'datetime', 'silence_warned_at' => 'datetime', 'confirmed_at' => 'datetime', 'last_agent_message_at' => 'datetime', 'reversed_at' => 'datetime',
            'position_update_sent_at' => 'datetime', 'awaiting_reply_since' => 'datetime', 'apology_sent_at' => 'datetime', 'overdue_alerted_at' => 'datetime',
            'last_ack_at' => 'datetime', 'review_requested_at' => 'datetime', 'reviewed_at' => 'datetime', 'review_stars' => 'integer', 'review_message_id' => 'integer',
            'return_priority_until' => 'datetime', 'waiting_messages' => 'array', 'bot_summary' => 'array', 'sla_met' => 'boolean', 'is_test' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function reservedFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ShiftMember::class, 'shift_member_id');
    }

    /** The entry she came back from (a reopen, a transfer, an escalation or a no-reply hand-off). */
    public function reopenedFrom(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'reopened_from_entry_id');
    }

    /** Her open support case when she took this ticket (flow revision §6). */
    public function openCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'open_case_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
