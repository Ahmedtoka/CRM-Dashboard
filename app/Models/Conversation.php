<?php

namespace App\Models;

use App\Enums\ConversationPriority;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    /** @use HasFactory<\Database\Factories\ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'channel_account_id',
        'platform',
        'status',
        'priority',
        'handler',
        'needs_human',
        'source',
        'source_comment_id',
        'first_responder_id',
        'last_responder_id',
        'locked_by_id',
        'locked_until',
        'unread_count',
        'last_message_at',
        'last_customer_message_at',
        'first_response_at',
        'handover_at',
        'resolved_at',
        'resolved_by_id',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => ConversationStatus::class,
            'priority' => ConversationPriority::class,
            'handler' => Handler::class,
            'needs_human' => 'boolean',
            'source' => ConversationSource::class,
            'locked_until' => 'datetime',
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'first_response_at' => 'datetime',
            'handover_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<ChannelAccount, $this>
     */
    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<ConversationNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ConversationNote::class);
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function firstResponder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_responder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastResponder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_responder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
