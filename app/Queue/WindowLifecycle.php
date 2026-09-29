<?php

namespace App\Queue;

use App\Enums\Handler;
use App\Events\ConversationUpdated;
use App\Models\QueueEntry;
use App\Queue\Events\QueueEntryUpdated;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\Jobs\SendQueueMessage;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\DB;

/**
 * A moderator window from delivery to close. Task 7 fills the rest
 * (silence warn / auto close, manual close, reopen, escalation).
 */
class WindowLifecycle
{
    /** Task 7: the customer came back inside the confirm window — undo the close's points. */
    public function reverseClose(QueueEntry $e): void {}

    /**
     * Close this window as `transfer` and put the customer back in the lounge as `returning`
     * (same ticket, no reserved moderator) so the router gives her to someone else.
     * Minimal version for the offline hand-off (Task 5); Task 7 routes it through close().
     * Returns the new waiting entry, or null when the window was no longer open.
     */
    public function transferAway(QueueEntry $e, string $why): ?QueueEntry
    {
        $new = DB::transaction(function () use ($e) {
            $e = QueueEntry::query()->lockForUpdate()->find($e->id);

            if ($e === null || ! $e->isOpen()) {
                return null;
            }

            $ticket = $e->ticket_no;
            $c = $e->conversation;
            // unique [business_date, ticket_no]: the returning entry keeps the ticket, so the closed one moves aside first.
            $e->forceFill([
                'status' => 'closed', 'close_reason' => 'transfer', 'closed_at' => now(),
                'handle_seconds' => $e->delivered_at ? (int) $e->delivered_at->diffInSeconds(now()) : 0,
                'ticket_no' => $ticket + 100000,
            ])->save();

            $new = QueueEntry::create($e->only(['conversation_id', 'customer_id', 'business_date', 'kind', 'bot_summary', 'is_test', 'last_customer_message_at']) + [
                'ticket_no' => $ticket, 'priority' => 'returning', 'status' => 'waiting', 'shift_id' => $e->shift_id, 'enqueued_at' => now(),
                'reopened_from_entry_id' => $e->id, 'reopen_count' => $e->reopen_count, 'waiting_messages' => ['5' => true, '3' => true, '1' => true],
            ]);

            $c?->forceFill(['assignee_id' => null, 'assigned_at' => null, 'queue_entry_id' => $new->id, 'handler' => Handler::Human, 'needs_human' => true])->save();

            return $new;
        });

        if ($new === null) {
            return null;
        }

        $old = QueueEntry::query()->find($e->id);

        if ($m = $old?->member) {
            if (in_array($m->status, ['busy', 'available'], true)) {
                $m->update(['status' => $m->openEntries()->exists() ? 'busy' : 'available']);
            }
            SafeBroadcast::send(new QueueMemberUpdated($m->fresh()));
        }

        SendQueueMessage::dispatch($new->id, 'queue_reassigned', []);
        SafeBroadcast::send(new QueueEntryUpdated($old));
        SafeBroadcast::send(new QueueEntryUpdated($new));

        if ($c = $new->conversation) {
            SafeBroadcast::send(new ConversationUpdated($c));
        }

        app(QueueRouter::class)->runAfterCommit('تحويل بسبب '.$why);

        return $new;
    }
}
