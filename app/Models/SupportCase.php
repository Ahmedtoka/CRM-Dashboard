<?php

namespace App\Models;

use Database\Factories\SupportCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer request a guided flow collected for staff to act on (design §4):
 * a return/exchange (or, since 2026-09-19, a `return` or an `exchange`), complaint, cancel/edit or delivery follow-up. `data`
 * holds every flow field; the conversation itself stays with the bot.
 * (`Case` is a PHP keyword, hence the name.)
 */
class SupportCase extends Model
{
    /** @use HasFactory<SupportCaseFactory> */
    use HasFactory;

    public const TYPES = ['return_exchange', 'return', 'exchange', 'complaint', 'cancel_edit', 'delivery_followup'];

    public const STATUSES = ['new', 'in_progress', 'closed'];

    public const PRIORITIES = ['medium', 'high'];

    public const TYPE_LABELS = [
        'return_exchange' => 'مرتجع/استبدال',
        'return' => 'مرتجع',
        'exchange' => 'استبدال',
        'complaint' => 'شكوى',
        'cancel_edit' => 'إلغاء/تعديل أوردر',
        'delivery_followup' => 'متابعة شحن',
    ];

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'platform',
        'type',
        'status',
        'priority',
        'order_id',
        'order_number',
        'data',
        'photo_attachment_ids',
        'policy_notes',
        'summary',
        'assigned_to_id',
        'closed_at',
        'closed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'photo_attachment_ids' => 'array',
            'policy_notes' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? (string) $this->type;
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }
}
