<?php

namespace App\Analytics;

use App\Enums\ActorType;
use App\Enums\Platform;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    public const MESSAGE_RECEIVED = 'message.received';

    public const MESSAGE_SENT = 'message.sent';

    public const MESSAGE_FAILED = 'message.failed';

    public const CONVERSATION_FIRST_RESPONSE = 'conversation.first_response';

    public const CONVERSATION_RESOLVED = 'conversation.resolved';

    public const CONVERSATION_REOPENED = 'conversation.reopened';

    public const CONVERSATION_HANDOVER = 'conversation.handover';

    public const CONVERSATION_PRIORITY_CHANGED = 'conversation.priority_changed';

    public const CONVERSATION_RETURN_TO_BOT = 'conversation.return_to_bot';

    public const NOTE_ADDED = 'note.added';

    public const COMMENT_REPLIED = 'comment.replied';

    public const COMMENT_HIDDEN = 'comment.hidden';

    public const COMMENT_PRIVATE_REPLY = 'comment.private_reply';

    public const COMMENT_FLAGGED = 'comment.flagged';

    public const ORDER_CREATED = 'order.created';

    public const ORDER_PAID = 'order.paid';

    public const ORDER_CANCELLED = 'order.cancelled';

    /** A paid signal (webhook/manual) arrived for an order that was not awaiting payment. */
    public const ORDER_PAID_IGNORED = 'order.paid_ignored';

    /** The local cancel succeeded but cancelling on Shopify failed. */
    public const ORDER_CANCEL_SYNC_FAILED = 'order.cancel_sync_failed';

    public const ORDER_RETRIED = 'order.retried';

    public const SHIPMENT_UPDATED = 'shipment.updated';

    public const BOT_RULE_MATCHED = 'bot.rule_matched';

    public const BOT_AI_REPLY = 'bot.ai_reply';

    public const BOT_OUTSIDE_HOURS = 'bot.outside_hours';

    public const USER_LOGIN = 'user.login';

    public const USER_LOGOUT = 'user.logout';

    public const CUSTOMER_MERGED = 'customer.merged';

    public function log(ActorType $actor, ?User $user, string $action, ?Model $subject = null, ?Conversation $conversation = null, array $meta = []): ActivityLog
    {
        $platform = $conversation?->platform;

        if ($platform === null && $subject !== null && $subject->getAttribute('platform') instanceof Platform) {
            $platform = $subject->getAttribute('platform');
        }

        return ActivityLog::create([
            'actor_type' => $actor,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'conversation_id' => $conversation?->id,
            'platform' => $platform,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
