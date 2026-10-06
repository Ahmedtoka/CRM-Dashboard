<?php

namespace App\Models;

use App\Inbox\Outcomes\Outcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One conversation episode's outcome (control room S3). Written only by OutcomeRecorder. */
class ConversationOutcome extends Model
{
    public const SOURCE_AUTO = 'auto';

    public const SOURCE_AGENT = 'agent';

    public const SOURCE_BOT = 'bot';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'set_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'reached_agent' => 'boolean',
            'first_message_id' => 'integer', 'last_message_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }

    public function outcomeEnum(): Outcome
    {
        return Outcome::from($this->outcome);
    }
}
