<?php

namespace App\Inbox\SavedReplies;

use App\Models\Conversation;
use App\Models\QuickReply;
use App\Models\QuickReplyUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a saved reply's use (spec §6): only called after a successful send
 * dispatch that carried a `quick_reply_id` the caller was allowed to use.
 */
final class QuickReplyUsageRecorder
{
    public function record(QuickReply $reply, User $user, Conversation $c): void
    {
        DB::transaction(function () use ($reply, $user, $c) {
            QuickReplyUsage::create(['quick_reply_id' => $reply->id, 'user_id' => $user->id, 'conversation_id' => $c->id, 'platform' => $c->platform, 'used_at' => now()]);
            QuickReply::query()->whereKey($reply->id)->update(['use_count' => DB::raw('use_count + 1'), 'last_used_at' => now()]);
        });
    }
}
