<?php

namespace App\Models;

use App\Bot\Router\TurnMetrics;
use Database\Factories\BotRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotRun extends Model
{
    /** @use HasFactory<BotRunFactory> */
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
        'tier',
        'burst_wait_ms',
        'planner_ms',
        'llm_ms',
        'tools_ms',
        'rounds',
        'cache_read_tokens',
        'cache_write_tokens',
    ];

    /** Bot hybrid P0: a run created during a turn carries where the turn's time went (TurnMetrics). */
    protected static function booted(): void
    {
        static::creating(function (BotRun $run) {
            foreach (app(TurnMetrics::class)->columns() as $column => $value) {
                if ($run->getAttribute($column) === null) {
                    $run->setAttribute($column, $value);
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'latency_ms' => 'integer',
            'burst_wait_ms' => 'integer',
            'planner_ms' => 'integer',
            'llm_ms' => 'integer',
            'tools_ms' => 'integer',
            'rounds' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
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
