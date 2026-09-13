<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotRun extends Model
{
    /** @use HasFactory<\Database\Factories\BotRunFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'comment_id',
        'trigger_message',
        'engine',
        'rule_id',
        'model',
        'intent',
        'confidence',
        'decision',
        'reply_text',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:4',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * @return BelongsTo<BotRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(BotRule::class, 'rule_id');
    }
}
