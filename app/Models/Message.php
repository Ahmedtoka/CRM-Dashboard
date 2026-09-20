<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\TestLinks\TestScope;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'platform',
        'is_test',
        'direction',
        'sender_type',
        'user_id',
        'body',
        'attachments',
        'buttons',
        'cards',
        'payload',
        'external_id',
        'status',
        'error',
        'is_template',
        'is_spam',
        'is_low_value',
        'queued_at_ms',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'direction' => MessageDirection::class,
            'sender_type' => SenderType::class,
            'attachments' => 'array',
            'buttons' => 'array',
            'cards' => 'array',
            'status' => MessageStatus::class,
            'is_template' => 'boolean',
            'is_spam' => 'boolean',
            'is_low_value' => 'boolean',
            'queued_at_ms' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    /**
     * Inherits the conversation's team-test flag (design 2026-09-21 §3), so every
     * message query in the reports can exclude the team's testing with one predicate.
     * On the insert itself, not on the `creating` event, for the reason given on
     * Conversation::performInsert().
     */
    protected function performInsert(Builder $query)
    {
        if (! array_key_exists('is_test', $this->attributes)) {
            $this->attributes['is_test'] = app(TestScope::class)->conversationIsTest($this->conversation_id);
        }

        return parent::performInsert($query);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function mediaAttachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('id');
    }
}
