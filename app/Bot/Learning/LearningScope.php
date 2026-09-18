<?php

namespace App\Bot\Learning;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Learning v2 §1: the bot learns from real conversations only. A conversation
 * is real when its channel account's `driver` is one of
 * `config('crm.learning.drivers')` (default `['live']`); the demo accounts use
 * `fake` and never reach a review, a note or a nightly report.
 *
 * This is the one place that filter lives — the per-conversation review, the
 * nightly `bot:learn` and the learning page all go through it.
 */
final class LearningScope
{
    /** @return list<string> */
    public static function drivers(): array
    {
        return array_values(array_map('strval', (array) config('crm.learning.drivers', ['live'])));
    }

    /** @return Builder<Conversation> */
    public static function conversations(): Builder
    {
        return Conversation::query()->whereIn('channel_account_id', self::channelAccountIds());
    }

    /** A subquery of the channel accounts whose conversations count, for `whereIn`. */
    public static function channelAccountIds(): Builder
    {
        return ChannelAccount::query()->whereIn('driver', self::drivers())->select('id');
    }

    public static function includes(Conversation $conversation): bool
    {
        return $conversation->channel_account_id !== null
            && self::conversations()->whereKey($conversation->id)->exists();
    }
}
