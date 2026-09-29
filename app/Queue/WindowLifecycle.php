<?php

namespace App\Queue;

use App\Analytics\ActivityLogger;
use App\Enums\ActorType;
use App\Enums\Handler;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Events\CloseConfirmed;
use App\Queue\Events\CloseReversed;
use App\Queue\Events\QueueEntryUpdated;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\Events\WindowClosed;
use App\Queue\Jobs\ConfirmClose;
use App\Queue\Jobs\SendQueueMessage;
use App\Support\SafeBroadcast;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A moderator window from delivery to close: first reply (SLA), silence warn / auto close,
 * manual close with a reason (inquiry / problem / case), the confirm window, escalation to the
 * leader and the transfer away from an offline moderator.
 *
 * Every state change happens inside one transaction holding the entry's row lock; real-time
 * pushes and customer messages leave only once it commits (`DB::afterCommit`, which runs at
 * once when there is no transaction). The plain `WindowClosed` / `CloseConfirmed` /
 * `CloseReversed` events fire inside the transaction so Part 2's points stay atomic with them.
 */
class WindowLifecycle
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /** The assignee's first reply in an open window: stamps `first_reply_at` and whether it met the first-reply SLA. */
    public function markFirstReply(Conversation $c, User $u): void
    {
        $e = $c->queue_entry_id ? QueueEntry::query()->find($c->queue_entry_id) : null;

        if (! $e || ! $e->isOpen() || $e->first_reply_at !== null || (int) $e->assigned_user_id !== (int) $u->id) {
            return;
        }

        $slaMet = max(0, (int) $e->enqueued_at->diffInSeconds(now())) <= QueueSetting::current()->sla_first_reply_seconds;
        // Conditional update: two concurrent sends never stamp it twice.
        $done = QueueEntry::query()->whereKey($e->id)->whereNull('first_reply_at')
            ->update(['first_reply_at' => now(), 'sla_met' => $slaMet, 'updated_at' => now()]);

        if ($done === 1) {
            DB::afterCommit(fn () => SafeBroadcast::send(new QueueEntryUpdated($e->fresh())));
        }
    }

    /**
     * Closes an open window for `$reason` (QueueEntry::CLOSE_REASONS). `$opts['case_type']` is the
     * support case type for a `case` close; `$opts['kind']` overrides the entry's kind.
     * A still-waiting entry resolved / cancelled elsewhere leaves the lounge as `cancelled`.
     * Anything else that is no longer open is returned untouched.
     */
    public function close(QueueEntry $e, string $reason, ?User $by = null, array $opts = []): QueueEntry
    {
        if (! in_array($reason, QueueEntry::CLOSE_REASONS, true)) {
            throw new InvalidArgumentException('Unknown close reason: '.$reason);
        }

        return DB::transaction(function () use ($e, $reason, $by, $opts) {
            $locked = QueueEntry::query()->lockForUpdate()->find($e->id);

            if ($locked === null) {
                return $e;
            }

            if ($locked->status === 'waiting' && in_array($reason, ['resolved_elsewhere', 'cancelled'], true)) {
                $locked->forceFill(['status' => 'cancelled', 'close_reason' => $reason, 'closed_at' => now(), 'closed_by_id' => $by?->id])->save();
                DB::afterCommit(fn () => SafeBroadcast::send(new QueueEntryUpdated($locked->fresh())));

                return $locked;
            }

            if (! $locked->isOpen()) {
                return $locked;
            }

            $this->closeLocked($locked, $reason, $by, $opts);

            return $locked->fresh();
        });
    }

    /** The close stood (no reopen): `confirmed_at` and Part 2's confirmed points. */
    public function confirm(QueueEntry $e): void
    {
        if (QueueEntry::query()->where('reopened_from_entry_id', $e->id)->exists()) {
            return;
        }

        $done = QueueEntry::query()->whereKey($e->id)->where('status', 'closed')->whereNull('confirmed_at')
            ->update(['confirmed_at' => now(), 'updated_at' => now()]);

        if ($done === 1) {
            event(new CloseConfirmed($e->fresh()));
        }
    }

    /** The customer came back inside the confirm window: Part 2's ScoreKeeper reverses the close's points. */
    public function reverseClose(QueueEntry $e): void
    {
        event(new CloseReversed($e));
    }

    /**
     * Frees the moderator's window at once and puts the customer in the escalation lane (same
     * ticket) for the shift leader. Returns the new waiting entry, or null when the window was
     * no longer open.
     */
    public function escalate(QueueEntry $e, User $by): ?QueueEntry
    {
        return $this->reroute($e, 'escalation', $by, 'escalation', 'تصعيد');
    }

    /**
     * Closes this window as `transfer` and puts the customer back in the lounge as `returning`
     * (same ticket, no reserved moderator) so the router gives her to someone else.
     * Returns the new waiting entry, or null when the window was no longer open.
     */
    public function transferAway(QueueEntry $e, string $why): ?QueueEntry
    {
        return $this->reroute($e, 'transfer', null, 'returning', 'تحويل بسبب '.$why);
    }

    /** Every open window: warn once at `silence_warn_seconds`, auto-close at `silence_close_seconds`. */
    public function tickSilence(): void
    {
        $s = QueueSetting::current();

        if (! $s->enabled) {
            return;
        }

        $open = QueueEntry::query()->with('conversation')->whereIn('status', QueueEntry::OPEN_STATUSES)->get();

        foreach ($open as $e) {
            rescue(function () use ($e, $s) {
                $idle = self::idleSeconds($e);

                if ($idle === null) {
                    return;
                }

                if ($idle >= $s->silence_close_seconds) {
                    $this->closeIfSilent($e, $s);

                    return;
                }

                if ($idle >= $s->silence_warn_seconds && $e->silence_warned_at === null) {
                    $done = QueueEntry::query()->whereKey($e->id)->whereNull('silence_warned_at')
                        ->update(['silence_warned_at' => now(), 'updated_at' => now()]);

                    if ($done === 1) {
                        SafeBroadcast::send(new QueueEntryUpdated($e->fresh()));
                    }
                }
            }, null, report: true);
        }
    }

    /** When the silence clock of an open window started: the later of her last message and the delivery. */
    public static function silentSince(QueueEntry $e): ?CarbonInterface
    {
        return collect([$e->conversation?->last_customer_message_at, $e->last_customer_message_at, $e->delivered_at])->filter()->max();
    }

    public static function idleSeconds(QueueEntry $e): ?int
    {
        $since = self::silentSince($e);

        return $since === null ? null : max(0, (int) $since->diffInSeconds(now()));
    }

    /**
     * Move a closed entry's ticket out of the way so a follow-up entry (transfer, escalation) can
     * keep the customer's number under the unique [business_date, ticket_no] index. The parked
     * number is the first free `ticket + k*100000`, so `ticket_no % 100000` stays the original.
     * Call inside the transaction that holds the entry's row lock.
     */
    public function parkTicket(QueueEntry $e): void
    {
        $date = $e->getRawOriginal('business_date');
        $park = $e->ticket_no + 100000;

        while (QueueEntry::query()->where('business_date', $date)->where('ticket_no', $park)->exists()) {
            $park += 100000;
        }

        $e->forceFill(['ticket_no' => $park])->save();
    }

    /** Auto-close, re-checking the silence under the row lock (she may have written meanwhile). */
    private function closeIfSilent(QueueEntry $e, QueueSetting $s): void
    {
        DB::transaction(function () use ($e, $s) {
            $locked = QueueEntry::query()->with('conversation')->lockForUpdate()->find($e->id);
            $idle = $locked && $locked->isOpen() ? self::idleSeconds($locked) : null;

            if ($idle !== null && $idle >= $s->silence_close_seconds) {
                $this->closeLocked($locked, 'auto', null, [], $s);
            }
        });
    }

    /**
     * Closes the window (`$reason`) and queues a follow-up entry in `$priority`'s lane keeping the
     * customer's ticket. The closed entry's ticket is parked BEFORE the new entry is created
     * (unique [business_date, ticket_no]).
     */
    private function reroute(QueueEntry $e, string $reason, ?User $by, string $priority, string $trigger): ?QueueEntry
    {
        return DB::transaction(function () use ($e, $reason, $by, $priority, $trigger) {
            $e = QueueEntry::query()->lockForUpdate()->find($e->id);

            if ($e === null || ! $e->isOpen()) {
                return null;
            }

            $ticket = $e->ticket_no;
            $this->closeLocked($e, $reason, $by, [], null, route: false);
            $this->parkTicket($e);

            $new = QueueEntry::create($e->only(['conversation_id', 'customer_id', 'business_date', 'kind', 'bot_summary', 'is_test', 'last_customer_message_at']) + [
                'ticket_no' => $ticket, 'priority' => $priority, 'status' => 'waiting', 'shift_id' => $e->shift_id, 'enqueued_at' => now(),
                'reopened_from_entry_id' => $e->id, 'reopen_count' => $e->reopen_count, 'waiting_messages' => ['5' => true, '3' => true, '1' => true],
            ]);

            $e->conversation?->forceFill([
                'assignee_id' => null, 'assigned_at' => null, 'queue_entry_id' => $new->id, 'handler' => Handler::Human, 'needs_human' => true,
            ])->save();

            if ($priority === 'returning') {
                SendQueueMessage::dispatch($new->id, 'queue_reassigned', []);
            }

            // closeLocked() already pushes the conversation (read fresh after commit, so with the new entry).
            DB::afterCommit(fn () => SafeBroadcast::send(new QueueEntryUpdated($new->fresh())));
            app(QueueRouter::class)->runAfterCommit($trigger.' #'.$ticket);

            return $new;
        });
    }

    /** The close itself, on an entry the caller holds locked and knows to be open. */
    private function closeLocked(QueueEntry $e, string $reason, ?User $by, array $opts = [], ?QueueSetting $s = null, bool $route = true): void
    {
        $s ??= QueueSetting::current();
        $c = $e->conversation;
        $handle = $e->delivered_at ? max(0, (int) $e->delivered_at->diffInSeconds(now())) : 0;
        $kind = $opts['kind'] ?? (in_array($reason, ['inquiry', 'problem', 'case'], true) ? $reason : $e->kind);

        $e->forceFill([
            'status' => 'closed', 'close_reason' => $reason, 'closed_at' => now(), 'closed_by_id' => $by?->id, 'handle_seconds' => $handle,
            'kind' => in_array($kind, QueueEntry::KINDS, true) ? $kind : $e->kind,
        ])->save();

        $c?->forceFill(['assignee_id' => null, 'assigned_at' => null])->save();

        if ($reason === 'auto' && $c) {
            $until = now()->addMinutes($s->return_priority_minutes);
            $c->forceFill(['return_priority_until' => $until])->save();
            $e->forceFill(['return_priority_until' => $until])->save();
            SendQueueMessage::dispatch($e->id, 'queue_auto_closed', []);
        }

        if ($reason === 'case' && $c) {
            $type = in_array($opts['case_type'] ?? null, SupportCase::TYPES, true) ? $opts['case_type'] : 'complaint';
            $case = SupportCase::create([
                'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'platform' => $c->platform, 'type' => $type, 'status' => 'new',
                'priority' => 'medium', 'summary' => $e->bot_summary['topic'] ?? null, 'data' => $e->bot_summary ?? [],
                'assigned_to_id' => $by?->id, 'opened_by_id' => $by?->id, 'queue_entry_id' => $e->id, 'sla_due_at' => now()->addHours($s->case_sla_hours),
            ]);
            $e->forceFill(['support_case_id' => $case->id])->save();
            SendQueueMessage::dispatch($e->id, 'queue_case_opened', ['case_id' => $case->id]);
        }

        $confirmNow = false;

        if (in_array($reason, ['inquiry', 'problem'], true)) {
            if ($s->close_confirm_minutes > 0) {
                ConfirmClose::dispatch($e->id, $e->closed_at->toIso8601String())->delay(now()->addMinutes($s->close_confirm_minutes));
            } else {
                $e->forceFill(['confirmed_at' => now()])->save();
                $confirmNow = true;
            }
        }

        // Her desk: `busy` → `available` once she has no open window left (per user, across member
        // rows). A pending break / break / offline stays as it is. No member row (a manual
        // assignment) → nothing to update.
        $m = $e->member;

        if ($m !== null) {
            if ($m->status === 'busy' && ! app(QueueRouter::class)->openForUser((int) $m->user_id)->exists()) {
                $m->update(['status' => 'available']);
            }

            DB::afterCommit(fn () => SafeBroadcast::send(new QueueMemberUpdated($m->fresh())));
        }

        $this->logger->log($by ? ActorType::User : ActorType::System, $by, ActivityLogger::QUEUE_CLOSE, null, $c, [
            'ticket' => $e->ticket_no, 'reason' => $reason, 'handle_seconds' => $handle,
        ]);

        event(new WindowClosed($e, $reason));

        if ($confirmNow) {
            event(new CloseConfirmed($e));
        }

        DB::afterCommit(function () use ($e, $c) {
            SafeBroadcast::send(new QueueEntryUpdated($e->fresh()));

            if ($c) {
                SafeBroadcast::send(new ConversationUpdated($c->fresh()));
            }
        });

        if ($route) {
            app(QueueRouter::class)->runAfterCommit('شباك فضي #'.$e->ticket_no);
        }
    }
}
