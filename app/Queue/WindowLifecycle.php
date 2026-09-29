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
 * Lock order, everywhere in the queue code: the CONVERSATION row first, then the QUEUE ENTRY
 * (`lockBoth()`), the same order the inbound message and the moderator's reply take them in.
 * Every state change happens inside one transaction (retried on a deadlock) holding both locks.
 *
 * Real-time pushes, customer messages and the plain `WindowClosed` / `CloseConfirmed` /
 * `CloseReversed` events leave only once it commits (`DB::afterCommit`, which runs at once when
 * there is no transaction). A listener that throws is reported and never fails the close, the
 * customer's inbound message, `resolve()` or `returnToBot()`.
 *
 * The customer-silence clock (`silentSince()`) starts at the ASSIGNEE's last reply and runs only
 * while that reply is later than the customer's last message. No reply yet, or she wrote last:
 * no clock, no warning, no auto-close (a slow moderator is an SLA matter).
 */
class WindowLifecycle
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Conversation first, then the entry (see the class docblock). Returns the locked entry with
     * the locked conversation as its relation. Call inside a transaction.
     */
    public static function lockBoth(int $entryId): ?QueueEntry
    {
        $conversationId = QueueEntry::query()->whereKey($entryId)->value('conversation_id');
        $c = $conversationId ? Conversation::query()->lockForUpdate()->find($conversationId) : null;
        $e = QueueEntry::query()->lockForUpdate()->find($entryId);

        if ($e !== null && $c !== null) {
            $e->setRelation('conversation', $c);
        }

        return $e;
    }

    /**
     * Every reply of the assignee in her open window: the customer-silence clock restarts here
     * (a new silence period, so the warning may be sent again) and the first one is the window's
     * first reply. Called inside the send's transaction, which already holds the conversation.
     */
    public function agentReplied(Conversation $c, User $u): void
    {
        $e = $c->queue_entry_id ? QueueEntry::query()->find($c->queue_entry_id) : null;

        if (! $e || ! $e->isOpen() || (int) $e->assigned_user_id !== (int) $u->id) {
            return;
        }

        $first = $e->first_reply_at === null;
        QueueEntry::query()->whereKey($e->id)->whereIn('status', QueueEntry::OPEN_STATUSES)
            ->update(['last_agent_message_at' => now(), 'silence_warned_at' => null, 'updated_at' => now()]);

        if ($first) {
            $this->markFirstReply($c, $u);
        } else {
            DB::afterCommit(fn () => SafeBroadcast::send(new QueueEntryUpdated($e->fresh())));
        }
    }

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
     * support case type for a `case` close; `$opts['kind']` overrides the entry's kind;
     * `$opts['note']` is why a waiting customer was taken out of the lounge (`close_note`).
     * A still-waiting entry resolved / cancelled elsewhere leaves the lounge as `cancelled`.
     * Anything else that is no longer open is returned untouched.
     */
    public function close(QueueEntry $e, string $reason, ?User $by = null, array $opts = []): QueueEntry
    {
        if (! in_array($reason, QueueEntry::CLOSE_REASONS, true)) {
            throw new InvalidArgumentException('Unknown close reason: '.$reason);
        }

        return DB::transaction(function () use ($e, $reason, $by, $opts) {
            $locked = self::lockBoth($e->id);

            if ($locked === null) {
                return $e;
            }

            if ($locked->status === 'waiting' && in_array($reason, ['resolved_elsewhere', 'cancelled'], true)) {
                $note = is_string($opts['note'] ?? null) && trim($opts['note']) !== '' ? mb_substr(trim($opts['note']), 0, 200) : null;
                $locked->forceFill(['status' => 'cancelled', 'close_reason' => $reason, 'close_note' => $note, 'closed_at' => now(), 'closed_by_id' => $by?->id])->save();
                $this->logger->log($by ? ActorType::User : ActorType::System, $by, ActivityLogger::QUEUE_CLOSE, null, $locked->conversation, [
                    'ticket' => $locked->ticket_no, 'reason' => $reason, 'handle_seconds' => null, 'left_the_lounge' => true,
                ]);
                DB::afterCommit(fn () => SafeBroadcast::send(new QueueEntryUpdated($locked->fresh())));

                return $locked;
            }

            if (! $locked->isOpen()) {
                return $locked;
            }

            $this->closeLocked($locked, $reason, $by, $opts);

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * The close stood (no reopen): `confirmed_at` and Part 2's confirmed points. Only an
     * inquiry / problem close that is neither confirmed nor reversed; decided under the row lock,
     * so one close can never end up both confirmed and reversed. True when it confirmed now.
     */
    public function confirm(QueueEntry $e): bool
    {
        return DB::transaction(function () use ($e) {
            $locked = self::lockBoth($e->id);

            if ($locked === null || ! self::awaitsConfirmation($locked)) {
                return false;
            }

            $locked->forceFill(['confirmed_at' => now()])->save();
            $this->announce(new CloseConfirmed($locked));

            return true;
        }, attempts: 3);
    }

    /**
     * Safety net for a lost / failed `ConfirmClose` job: confirms every manual close whose
     * confirmation time has passed and that is neither confirmed nor reversed. Returns how many
     * it confirmed. (`queue:tick` will call it.)
     */
    public function confirmDue(): int
    {
        $before = now()->subMinutes((int) QueueSetting::current()->close_confirm_minutes);
        $ids = QueueEntry::query()->where('status', 'closed')->whereIn('close_reason', QueueEntry::CONFIRMABLE_REASONS)
            ->whereNull('confirmed_at')->whereNull('reversed_at')->where('closed_at', '<=', $before)->orderBy('id')->pluck('id');
        $n = 0;

        foreach ($ids as $id) {
            $n += rescue(fn () => (int) $this->confirm(QueueEntry::query()->findOrFail($id)), 0, true);
        }

        return $n;
    }

    /**
     * The customer came back inside the confirm window: the close is reversed (Part 2's
     * ScoreKeeper takes the points back). At most once per close, never after it was confirmed;
     * decided under the row lock. True when it reversed now.
     */
    public function reverseClose(QueueEntry $e): bool
    {
        return DB::transaction(function () use ($e) {
            $locked = self::lockBoth($e->id);

            if ($locked === null || ! self::awaitsConfirmation($locked)) {
                return false;
            }

            $locked->forceFill(['reversed_at' => now()])->save();
            $this->announce(new CloseReversed($locked));

            return true;
        }, attempts: 3);
    }

    /** A manual (inquiry / problem) close still inside its story: not confirmed, not reversed. */
    public static function awaitsConfirmation(QueueEntry $e): bool
    {
        return $e->status === 'closed' && in_array($e->close_reason, QueueEntry::CONFIRMABLE_REASONS, true)
            && $e->confirmed_at === null && $e->reversed_at === null;
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

    /**
     * Every open window whose customer is silent after the moderator's last reply: one warning
     * message per silence period at `silence_warn_seconds`, auto-close at `silence_close_seconds`.
     */
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
                    $this->warnIfSilent($e, $s);
                }
            }, null, report: true);
        }
    }

    /**
     * When the customer-silence clock of this window started: the assignee's last reply, and
     * only while it is later than the customer's last message. Null (no clock) when the
     * moderator has not replied yet or the customer wrote last.
     */
    public static function silentSince(QueueEntry $e): ?CarbonInterface
    {
        $agent = $e->last_agent_message_at;

        if ($agent === null) {
            return null;
        }

        $customer = collect([$e->conversation?->last_customer_message_at, $e->last_customer_message_at])->filter()->max();

        return $customer !== null && $customer->greaterThanOrEqualTo($agent) ? null : $agent;
    }

    /** Seconds the customer has been silent since the moderator's last reply; null when the clock is not running. */
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

    /** The warning, once per silence period, re-checking the silence under the locks. */
    private function warnIfSilent(QueueEntry $e, QueueSetting $s): void
    {
        DB::transaction(function () use ($e, $s) {
            $locked = self::lockBoth($e->id);
            $idle = $locked && $locked->isOpen() ? self::idleSeconds($locked) : null;

            if ($idle === null || $idle < $s->silence_warn_seconds || $locked->silence_warned_at !== null) {
                return;
            }

            $locked->forceFill(['silence_warned_at' => now()])->save();
            SendQueueMessage::dispatch($locked->id, 'queue_silence_warning', [
                'minutes' => max(1, (int) ceil(($s->silence_close_seconds - $idle) / 60)),
            ]);
            DB::afterCommit(fn () => SafeBroadcast::send(new QueueEntryUpdated($locked->fresh())));
        }, attempts: 3);
    }

    /** Auto-close, re-checking the silence under the locks (she may have written meanwhile). */
    private function closeIfSilent(QueueEntry $e, QueueSetting $s): void
    {
        DB::transaction(function () use ($e, $s) {
            $locked = self::lockBoth($e->id);
            $idle = $locked && $locked->isOpen() ? self::idleSeconds($locked) : null;

            if ($idle !== null && $idle >= $s->silence_close_seconds) {
                $this->closeLocked($locked, 'auto', null, [], $s);
            }
        }, attempts: 3);
    }

    /** A plain event, once the transaction committed; a throwing listener is reported, never rethrown. */
    private function announce(object $event): void
    {
        DB::afterCommit(fn () => rescue(fn () => event($event), null, true));
    }

    /**
     * Closes the window (`$reason`) and queues a follow-up entry in `$priority`'s lane keeping the
     * customer's ticket. The closed entry's ticket is parked BEFORE the new entry is created
     * (unique [business_date, ticket_no]).
     */
    private function reroute(QueueEntry $e, string $reason, ?User $by, string $priority, string $trigger): ?QueueEntry
    {
        return DB::transaction(function () use ($e, $reason, $by, $priority, $trigger) {
            $e = self::lockBoth($e->id);

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
        }, attempts: 3);
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

        if (in_array($reason, QueueEntry::CONFIRMABLE_REASONS, true)) {
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

        $this->announce(new WindowClosed($e, $reason));

        if ($confirmNow) {
            $this->announce(new CloseConfirmed($e));
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
