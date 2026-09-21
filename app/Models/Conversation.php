<?php

namespace App\Models;

use App\Enums\ConversationPriority;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\Platform;
use App\TestLinks\TestScope;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'channel_account_id',
        'platform',
        'is_test',
        'status',
        'priority',
        'handler',
        'needs_human',
        'source',
        // Bilingual bot (design 2026-09-21 §1): 'ar' | 'en', decided from her own messages.
        'language',
        'source_comment_id',
        'first_responder_id',
        'last_responder_id',
        'locked_by_id',
        'locked_until',
        'claimed_until',
        'unread_count',
        'last_message_at',
        'last_customer_message_at',
        'first_response_at',
        'handover_at',
        'resolved_at',
        'resolved_by_id',
        'bot_due_at',
        'bot_state',
        'priority_level',
        'handover_category',
        'handover_topic',
        'queue',
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
            'claimed_until' => 'datetime',
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'first_response_at' => 'datetime',
            'handover_at' => 'datetime',
            'resolved_at' => 'datetime',
            'bot_due_at' => 'datetime',
            'bot_state' => 'array',
            'is_test' => 'boolean',
        ];
    }

    /**
     * Team-test conversations (design 2026-09-21 §3) are flagged once, when the row is
     * written, from their channel account's `driver`. The reports and the nightly rollup
     * read this column instead of joining to `channel_accounts` on every aggregate.
     *
     * Done here rather than on the `creating` model event on purpose: a test that calls
     * `Event::fake()` silences model events, and a conversation that quietly lost its
     * flag would land in the owner's real numbers.
     */
    protected function performInsert(Builder $query)
    {
        if (! array_key_exists('is_test', $this->attributes)) {
            $this->attributes['is_test'] = app(TestScope::class)->accountIsTest($this->channel_account_id);
        }

        $inserted = parent::performInsert($query);

        if ($inserted) {
            app(TestScope::class)->noteConversation((int) $this->getKey(), (bool) $this->attributes['is_test']);
        }

        return $inserted;
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
     * @return HasMany<SupportCase, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(SupportCase::class);
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

    /**
     * The bot_state a conversation keeps once a human resolves it or hands it back
     * to the bot (human bot flow Task 5 ruling 6a): only the burst turn marker, so
     * stale clarified/repeat_count/last_intents/asks/collected can never trigger a
     * "repeated" or clarify handover when the customer writes again. The
     * once-per-conversation delayed-response flag (final fix wave I2) is kept too.
     * Null when neither was set. Returned, not saved — callers persist it.
     *
     * @return array{last_turn_message_id?: int|string, delayed_response_sent?: true}|null
     */
    public function resetBotState(): ?array
    {
        $state = $this->bot_state ?? [];
        $kept = [];

        if (($state['last_turn_message_id'] ?? null) !== null) {
            $kept['last_turn_message_id'] = $state['last_turn_message_id'];
        }

        if (! empty($state['delayed_response_sent'])) {
            $kept['delayed_response_sent'] = true;
        }

        return $kept !== [] ? $kept : null;
    }
}
