<?php

namespace App\Models;

use Database\Factories\SupportCaseFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The raw Arabic type words. Kept for the flow sandbox log (SandboxCaseRecorder),
     * which writes the owner's own Arabic; the staff-facing label is `typeLabel()`.
     */
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
        'opened_by_id',
        'queue_entry_id',
        'sla_due_at',
        'resolved_at',
        'resolved_by_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'photo_attachment_ids' => 'array',
            'policy_notes' => 'array',
            'closed_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * The id of the customer's open case (not closed, not resolved), newest first: on her customer
     * record, else — no customer — on this conversation. The same rule as QueueService::openCaseFor,
     * so the inbox chip and the ticket agree (flow revision §6).
     */
    public static function openIdFor(Conversation $c): ?int
    {
        $id = static::query()->where('status', '!=', 'closed')->whereNull('resolved_at')
            ->when($c->customer_id !== null, fn ($q) => $q->where('customer_id', $c->customer_id), fn ($q) => $q->where('conversation_id', $c->id))
            ->latest('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * The same lookup as a correlated sub-select over `conversations`, so a list of conversations
     * carries `open_case_id` in its one query instead of one per row.
     *
     * @return Builder<SupportCase>
     */
    public static function openIdSubquery(): Builder
    {
        return static::query()->select('support_cases.id')
            ->where('support_cases.status', '!=', 'closed')->whereNull('support_cases.resolved_at')
            ->where(fn (Builder $w) => $w
                ->whereColumn('support_cases.customer_id', 'conversations.customer_id')
                ->orWhere(fn (Builder $x) => $x->whereNull('conversations.customer_id')->whereColumn('support_cases.conversation_id', 'conversations.id')))
            ->orderByDesc('support_cases.id')->limit(1);
    }

    /** The case type in the viewer's language. */
    public function typeLabel(): string
    {
        $key = 'cases.types.'.$this->type;

        return ($label = __($key)) !== $key ? (string) $label : (string) $this->type;
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

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    /** The queue ticket whose window was closed as a case (null for a flow-collected case). @return BelongsTo<QueueEntry, $this> */
    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class);
    }
}
