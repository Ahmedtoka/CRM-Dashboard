<?php

namespace App\Queue;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Message;
use App\Models\QueueEntry;

/**
 * Close-cycle hotfixes (2026-10-17, C1 #1 — D-01, D-02): what is said AFTER a close — the closing
 * message («سعدنا بخدمتك») and the rating question — only while the close is still the last word.
 * Checked when the job sends (`SendQueueMessage`, `RatingService`) and again right before the
 * platform call (`SendOutboundMessage::$afterCloseOf`, H-03). Stale when, since the close:
 *  - `newer_entry`: a later queue entry of the conversation exists (she is back in the queue);
 *  - `customer_back`: she wrote a real message (not spam, not an acknowledgement such as «شكراً»);
 *  - `staff_replied`: somebody of the team replied from the CRM (an outbound staff message with a
 *    user). Echoes from Business Suite are not counted yet (H-05: automatic Meta replies are
 *    echoes too, to be tried on a real page first).
 *
 * «Since» is strictly after `closed_at` (second precision, like the rating's `wroteSince`): the
 * message she was answered on can share the close's second.
 */
class AfterCloseGuard
{
    /** Why nothing should be said after this close any more; null while it still stands. */
    public static function reason(QueueEntry $e): ?string
    {
        if ($e->closed_at === null) {
            return 'not_closed';
        }

        if (QueueEntry::query()->where('conversation_id', $e->conversation_id)->where('id', '>', $e->id)->exists()) {
            return 'newer_entry';
        }

        if (self::customerWroteSince($e)) {
            return 'customer_back';
        }

        if (self::staffRepliedSince($e)) {
            return 'staff_replied';
        }

        return null;
    }

    /** A real message of hers after the close (an acknowledgement or spam does not count). */
    public static function customerWroteSince(QueueEntry $e): bool
    {
        // Close cycle RC-6: the ingest marked it under the conversation lock — also a message whose
        // `created_at` is not later than the close (it was inserted before the lock was taken).
        if ($e->customer_back_at !== null) {
            return true;
        }

        $ack = app(Acknowledgement::class);

        return Message::query()->where('conversation_id', $e->conversation_id)->where('direction', MessageDirection::In->value)
            ->where('is_spam', false)->where('created_at', '>', $e->closed_at)->get(['id', 'body', 'attachments', 'payload'])
            ->contains(fn (Message $m) => ! $ack->matches($m));
    }

    /** A reply from the CRM by any staff member after the close (and, when echoes count as staff, a person's echo). */
    public static function staffRepliedSince(QueueEntry $e): bool
    {
        return self::staffRepliedAfter((int) $e->conversation_id, $e->closed_at);
    }

    /** The same rule from a moment (a close with no queue entry, §4.4). */
    public static function staffRepliedAfter(int $conversationId, \Carbon\CarbonInterface $at): bool
    {
        return Message::query()->where('conversation_id', $conversationId)->where('direction', MessageDirection::Out->value)
            ->where('sender_type', SenderType::User->value)->where('created_at', '>', $at)
            ->where(fn ($q) => $q->whereNotNull('user_id')->when(EchoPolicy::countsAsStaff(), fn ($q) => $q->orWhere(fn ($q) => EchoPolicy::human($q))))
            ->exists();
    }

    /** A real message of hers after a moment (a close with no queue entry). */
    public static function customerWroteAfter(int $conversationId, \Carbon\CarbonInterface $at): bool
    {
        $ack = app(Acknowledgement::class);

        return Message::query()->where('conversation_id', $conversationId)->where('direction', MessageDirection::In->value)
            ->where('is_spam', false)->where('created_at', '>', $at)->get(['id', 'body', 'attachments', 'payload'])
            ->contains(fn (Message $m) => ! $ack->matches($m));
    }
}
