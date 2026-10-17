<?php

namespace App\Models;

use App\Enums\OrderConfirmationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp confirmation per Shopify order (spec 2026-10-16).
 *
 * @property OrderConfirmationStatus $status
 */
class OrderConfirmation extends Model
{
    use HasFactory;

    public const ANSWERS = ['confirm', 'edit', 'cancel'];

    public const CALL_OUTCOMES = ['confirmed', 'cancelled', 'unreachable'];

    protected $fillable = [
        'order_id', 'channel_account_id', 'conversation_id', 'customer_id', 'phone_e164',
        'status', 'skip_reason', 'send_at', 'sent_at', 'reminded_at', 'answered_at', 'answer',
        'edit_note', 'edit_note_requested_at', 'case_id', 'outbound_message_id', 'reminder_message_id',
        'last_error', 'pending_tags', 'tag_synced_at', 'call_outcome', 'called_by', 'called_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderConfirmationStatus::class,
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'reminded_at' => 'datetime',
            'answered_at' => 'datetime',
            'edit_note_requested_at' => 'datetime',
            'tag_synced_at' => 'datetime',
            'called_at' => 'datetime',
            'pending_tags' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        // Load-test orders are hidden by Order's global scope; a confirmation never targets one.
        return $this->belongsTo(Order::class);
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class, 'case_id');
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    public function scopeStatus(Builder $q, OrderConfirmationStatus ...$statuses): Builder
    {
        return $q->whereIn('status', array_map(fn ($s) => $s->value, $statuses));
    }

    public function scopeNeedsCall(Builder $q): Builder
    {
        return $q->where('status', OrderConfirmationStatus::NoResponse->value);
    }
}
