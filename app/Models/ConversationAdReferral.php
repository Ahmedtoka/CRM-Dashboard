<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One ad referral of a conversation (A4, R-08). Append-only: written by App\Inbox\AdAttribution and
 * `ads:backfill-referrals`, read by App\Ads\Attribution\OrderAttribution.
 */
class ConversationAdReferral extends Model
{
    public $timestamps = false;

    protected $fillable = ['conversation_id', 'customer_id', 'ad_external_id', 'referred_at'];

    protected function casts(): array
    {
        return ['referred_at' => 'datetime'];
    }

    /** Append-only: an existing row is never saved again (does not depend on model events, which tests may fake). */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('conversation_ad_referrals is append-only.');
        }

        return parent::save($options);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
