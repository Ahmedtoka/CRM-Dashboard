<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One per-conversation learning review (learning v2 §2): the short notes the
 * review wrote about a finished conversation, the newest message it covered
 * and what the call cost. The nightly report reads the day's rows and stamps
 * them with `used_in_report_id`.
 *
 * @property list<array{kind:string, summary:string, quote:string, agent_answer?:string}> $notes
 */
class BotLearningNote extends Model
{
    public const KINDS = ['unanswered', 'wrong_answer', 'agent_knowledge', 'new_phrasing', 'flow_friction'];

    /** A real customer conversation. */
    public const SOURCE_LIVE = 'live';

    /** One of the team's runs of a public test link (design 2026-09-21 §5). */
    public const SOURCE_TEST = 'test';

    public const SOURCES = [self::SOURCE_LIVE, self::SOURCE_TEST];

    protected $fillable = [
        'conversation_id',
        'channel_account_id',
        'source',
        'last_message_id',
        'notes',
        'model',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'used_in_report_id',
    ];

    protected function casts(): array
    {
        return [
            'notes' => 'array',
            'last_message_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'float',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<ChannelAccount, $this> */
    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    /** @return BelongsTo<BotLearningReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(BotLearningReport::class, 'used_in_report_id');
    }
}
