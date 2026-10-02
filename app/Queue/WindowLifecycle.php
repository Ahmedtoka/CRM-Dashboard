<?php

namespace App\Queue;

use App\Analytics\ActivityLogger;
use App\Analytics\PresenceTracker;
use App\Bot\BotEngine;
use App\Enums\ActorType;
use App\Enums\Handler;
use App\Events\ConversationUpdated;
use App\Inbox\OutboundService;
use App\Inbox\UserNotifier;
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
use App\Queue\Jobs\RequestRating;
use App\Queue\Jobs\SendQueueMessage;
use App\Support\SafeBroadcast;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
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
 *
 * An acknowledgement (thanks, emoji, a sticker — spec 2026-09-30 §1) starts neither clock again:
 * the customer-silence clock keeps running from the assignee's last reply (`last_ack_at`), so a
 * window she forgets to close still warns and auto-closes.
 *
 * The moderator-reply clock (flow revision §4, `awaiting_reply_since`) runs only while the
 * customer waits for the ASSIGNEE: from delivery, or from the customer's message after the
 * assignee had answered everything; every reply of the assignee stops it. The two clocks never
 * run together. `tickReplies()` apologises, then hands the window off (`no_reply`) or alerts.
 * A moderator who is not logged in is never handed off (nor penalised) by this clock: her
 * windows follow the offline path of `ShiftService::tickMembers()`.
 */
class WindowLifecycle
{
    /**
     * The no-reply hand-off never goes out in the tick that sent the apology: the apology must be
     * at least this old (one tick), so she never reads «زميلتنا X معاكي حالاً» and, seconds later,
     * «دورك جه… الموظفة Y» (a tick gap after a deploy, or an apology close to the limit).
     */
    public const APOLOGY_BEFORE_HANDOFF_SECONDS = 30;

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly PresenceTracker $presence,
    ) {}

    /**
     * Conversation first, then the entry (see the class docblock). Returns the locked entry with
     * the locked conversation as its relation. Call inside a transaction.
     *
     * The FIRST statement of the transaction is a locking read (the conversation, by the entry's
     * `conversation_id`, which never changes). On MySQL / MariaDB (REPEATABLE READ) the first
     * plain SELECT fixes the snapshot every later plain SELECT answers from; starting with a
     * locking read means the snapshot is taken only after the locks are held, so the counts read
     * under them (open windows, window numbers) see what a concurrent assignment committed.
     */
    public static function lockBoth(QueueEntry $entry): ?QueueEntry
    {
        $c = $entry->conversation_id ? Conversation::query()->lockForUpdate()->find($entry->conversation_id) : null;
        $e = QueueEntry::query()->lockForUpdate()->find($entry->id);

        if ($e !== null && $c !== null) {
            $e->setRelation('conversation', $c);
        }

        return $e;
    }

    /**
     * Every reply of the assignee in her open window: the customer-silence clock restarts here
     * (a new silence period, so the warning may be sent again), the moderator-reply clock stops
     * (a new waiting period for the apology and the leader alert), and the first one is the
     * window's first reply. Called inside the send's transaction, which already holds the conversation.
     */
    public function agentReplied(Conversation $c, User $u): void
    {
        $e = $c->queue_entry_id ? QueueEntry::query()->find($c->queue_entry_id) : null;

        if (! $e || ! $e->isOpen() || (int) $e->assigned_user_id !== (int) $u->id) {
            return;
        }

        $first = $e->first_reply_at === null;
        QueueEntry::query()->whereKey($e->id)->whereIn('status', QueueEntry::OPEN_STATUSES)->update([
            'last_agent_message_at' => now(), 'silence_warned_at' => null,
            'awaiting_reply_since' => null, 'apology_sent_at' => null, 'overdue_alerted_at' => null, 'updated_at' => now(),
        ]);

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
            $locked = self::lockBoth($e);

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
            $locked = self::lockBoth($e);

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
    public function confirmDue(?QueueSetting $settings = null): int
    {
        $before = now()->subMinutes((int) ($settings ?? QueueSetting::current())->close_confirm_minutes);
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
            $locked = self::lockBoth($e);

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
    public function tickSilence(?QueueSetting $settings = null): void
    {
        $s = $settings ?? QueueSetting::current();

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

        // An acknowledgement (spec 2026-09-30 §1) is not an answer: while her latest message is one
        // (the conversation's time is not later than `last_ack_at`), only her last real message counts.
        $conversation = $e->conversation?->last_customer_message_at;

        if ($conversation !== null && $e->last_ack_at !== null && $conversation->lessThanOrEqualTo($e->last_ack_at)) {
            $conversation = null;
        }

        $customer = collect([$conversation, $e->last_customer_message_at])->filter()->max();

        return $customer !== null && $customer->greaterThanOrEqualTo($agent) ? null : $agent;
    }

    /** Seconds the customer has been silent since the moderator's last reply; null when the clock is not running. */
    public static function idleSeconds(QueueEntry $e): ?int
    {
        $since = self::silentSince($e);

        return $since === null ? null : max(0, (int) $since->diffInSeconds(now()));
    }

    /** Seconds the customer has been waiting for the assignee in this open window; null when she is not (no clock). */
    public static function awaitingSeconds(QueueEntry $e): ?int
    {
        if (! $e->isOpen() || $e->awaiting_reply_since === null) {
            return null;
        }

        return max(0, (int) $e->awaiting_reply_since->diffInSeconds(now()));
    }

    /** The apology of this waiting period went out at least one tick ago (never a hand-off in the same tick). */
    public static function apologySettled(QueueEntry $e): bool
    {
        return $e->apology_sent_at !== null
            && $e->apology_sent_at->lessThanOrEqualTo(now()->subSeconds(self::APOLOGY_BEFORE_HANDOFF_SECONDS));
    }

    /** The hand-off limit that applies: before her first reply, else for a later unanswered message. */
    public static function handOffLimit(QueueEntry $e, QueueSetting $s): int
    {
        return (int) ($e->first_reply_at === null ? $s->agent_reassign_first_seconds : $s->agent_reassign_seconds);
    }

    /** Seconds to the hand-off; null without a clock, and for an escalation (never handed off). */
    public static function handOffLeft(QueueEntry $e, ?QueueSetting $settings = null): ?int
    {
        $waited = self::awaitingSeconds($e);

        if ($waited === null || $e->priority === 'escalation') {
            return null;
        }

        return max(0, self::handOffLimit($e, $settings ?? QueueSetting::current()) - $waited);
    }

    /**
     * Flow revision §4: every open window whose customer waits for the assignee. At
     * `agent_apology_seconds` the customer gets one apology per waiting period and the moderator a
     * `queue.reply_overdue`; at the limit the window goes to another logged-in moderator with a
     * free window (`no_reply`), or, when nobody is free, stays and the leader hears once per
     * waiting period — tried again on every tick. An escalation is never handed off: the admins
     * hear instead. An assignee who is not logged in is left to the offline path (no hand-off,
     * no penalty, no alert). One window that throws never stops the others.
     */
    public function tickReplies(?QueueSetting $settings = null): void
    {
        $s = $settings ?? QueueSetting::current();

        if (! $s->enabled) {
            return;
        }

        $open = QueueEntry::query()->with(['conversation.customer', 'assignee'])->whereIn('status', QueueEntry::OPEN_STATUSES)
            ->whereNotNull('awaiting_reply_since')->orderBy('awaiting_reply_since')->get();

        foreach ($open as $e) {
            rescue(fn () => $this->tickReply($e, $s), null, report: true);
        }
    }

    /**
     * Flow revision §4.3: the customer waited the whole limit and another logged-in moderator has
     * a free window. Her window closes as `no_reply` (her handle time counted), the customer goes
     * back to the top of the lounge keeping her ticket (`returning`, parked like a transfer) with
     * `excluded_user_id` = the moderator who did not reply, so the router never gives her back.
     * No message to the customer (the apology already went out); a system line in the thread; the
     * activity log `queue.no_reply` records the penalty until Part 2's points ledger exists.
     * Re-checked under the locks: null when she replied (or the window closed) meanwhile, for an
     * escalation, when the assignee is not logged in (the offline path takes her windows, with no
     * penalty), when nobody is free any more, and until the apology is at least one tick old
     * (`apologySettled()`).
     */
    public function handOffNoReply(QueueEntry $e, ?QueueSetting $settings = null): ?QueueEntry
    {
        $s = $settings ?? QueueSetting::current();

        return DB::transaction(function () use ($e, $s) {
            $locked = self::lockBoth($e);
            $waited = $locked !== null ? self::awaitingSeconds($locked) : null;

            if ($waited === null || $locked->priority === 'escalation' || $waited < self::handOffLimit($locked, $s) || ! self::apologySettled($locked)) {
                return null;
            }

            // Plain reads, taken after the locks: her heartbeat and the colleagues' desks as they are now.
            $agent = $locked->assigned_user_id !== null ? User::query()->find($locked->assigned_user_id) : null;

            if (! $this->loggedIn($agent) || ! app(QueueRouter::class)->hasFreeDeskFor($locked, $s)) {
                return null;
            }

            $agentId = (int) $agent->id;
            $agentName = (string) $agent->name;
            $minutes = max(1, (int) round(self::handOffLimit($locked, $s) / 60));
            $ticket = $locked->ticket_no;
            $c = $locked->conversation;

            // Registered before the reroute's router run, so the thread reads the hand-off before the new call.
            $line = __('queue.system.no_reply_handoff', ['agent' => $agentName, 'minutes' => $minutes], 'ar');
            DB::afterCommit(fn () => rescue(fn () => $c !== null ? app(OutboundService::class)->sendSystem($c->fresh(), $line) : null, null, true));

            $new = $this->rerouteLocked($locked, 'no_reply', null, 'returning', 'ما ردّتش '.$agentName, ['excluded_user_id' => $agentId, 'tell_customer' => false]);

            $this->logger->log(ActorType::System, null, ActivityLogger::QUEUE_NO_REPLY, null, $c, [
                'ticket' => $ticket, 'entry_id' => $locked->id, 'user_id' => $agentId, 'minutes' => $minutes, 'points' => -$s->point('no_reply'),
            ]);

            return $new;
        }, attempts: 3);
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

    /** One window on the reply tick: the apology, then at the limit the hand-off or the alert. */
    private function tickReply(QueueEntry $e, QueueSetting $s): void
    {
        $waited = self::awaitingSeconds($e);

        if ($waited === null) {
            return;
        }

        if ($waited >= (int) $s->agent_apology_seconds && $e->apology_sent_at === null) {
            $this->apologise($e, $s);
        }

        if ($waited < self::handOffLimit($e, $s)) {
            return;
        }

        if ($e->priority === 'escalation') {
            $this->alertOverdue($e, $waited, true, $s);

            return;
        }

        // Not logged in: the offline path hands her windows off (after its own delay, no penalty).
        if (! $this->loggedIn($e->assignee)) {
            return;
        }

        if (app(QueueRouter::class)->hasFreeDeskFor($e, $s)) {
            // Not in the tick that sent the apology (`$e` was read before it): the next tick hands off.
            // Null: she replied (or the window closed, or the free desk filled) since the read.
            if (self::apologySettled($e)) {
                $this->handOffNoReply($e, $s);
            }

            return;
        }

        $this->alertOverdue($e, $waited, false, $s);
    }

    /** Logged in by the router's rule: an active account with a recent heartbeat. */
    private function loggedIn(?User $u): bool
    {
        return $u !== null && (bool) $u->is_active && $this->presence->isOnline($u);
    }

    /** The apology, once per waiting period, re-checked under the locks; the moderator hears once it is committed. */
    private function apologise(QueueEntry $e, QueueSetting $s): void
    {
        DB::transaction(function () use ($e, $s) {
            $locked = self::lockBoth($e);
            $waited = $locked !== null ? self::awaitingSeconds($locked) : null;

            if ($waited === null || $waited < (int) $s->agent_apology_seconds || $locked->apology_sent_at !== null) {
                return;
            }

            $locked->forceFill(['apology_sent_at' => now()])->save();
            $agent = $locked->assignee;
            SendQueueMessage::dispatch($locked->id, 'queue_agent_delay_apology', ['agent' => (string) ($agent?->name ?? '')]);

            DB::afterCommit(function () use ($locked, $agent, $s) {
                $fresh = $locked->fresh();

                if ($fresh === null) {
                    return;
                }

                SafeBroadcast::send(new QueueEntryUpdated($fresh));

                if ($agent !== null) {
                    rescue(fn () => app(UserNotifier::class)->notify($agent, 'queue.reply_overdue', $this->overdueData($fresh, $s)), null, true);
                }
            });
        }, attempts: 3);
    }

    /**
     * At the limit with nobody free (or on an escalation): the shift leader hears — the admins for
     * an escalation, the supervisors when the shift has no leader or the late window is the
     * leader's own — once per waiting period (`overdue_alerted_at`, claimed by a conditional
     * update, so two ticks never both alert). Sent at once: the claim is the only write.
     */
    private function alertOverdue(QueueEntry $e, int $waited, bool $escalation, QueueSetting $s): void
    {
        $claimed = QueueEntry::query()->whereKey($e->id)->whereIn('status', QueueEntry::OPEN_STATUSES)
            ->whereNotNull('awaiting_reply_since')->whereNull('overdue_alerted_at')
            ->update(['overdue_alerted_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $data = $this->overdueData($e, $s) + ['minutes' => intdiv($waited, 60), 'escalation' => $escalation];
        $notifier = app(UserNotifier::class);

        if ($escalation) {
            $notifier->notifyAdmins('queue.reply_overdue_leader', $data);

            return;
        }

        $leaderId = app(QueueService::class)->openShift()?->leader_user_id;
        $leader = $leaderId !== null && (int) $leaderId !== (int) $e->assigned_user_id
            ? User::query()->where('is_active', true)->find($leaderId)
            : null;

        if ($leader !== null) {
            $notifier->notify($leader, 'queue.reply_overdue_leader', $data);
        } else {
            $notifier->notifySupervisors('queue.reply_overdue_leader', $data);
        }
    }

    /** @return array<string, mixed> what the reply notifications carry */
    private function overdueData(QueueEntry $e, QueueSetting $s): array
    {
        $c = $e->conversation;

        return [
            'entry_id' => $e->id, 'conversation_id' => $e->conversation_id, 'ticket' => $e->ticket_no,
            'customer_name' => $c?->customer?->name, 'platform' => $c?->platform?->value,
            'agent_id' => $e->assigned_user_id !== null ? (int) $e->assigned_user_id : null, 'agent_name' => $e->assignee?->name,
            'handoff_seconds' => self::handOffLeft($e, $s),
        ];
    }

    /** The warning, once per silence period, re-checking the silence under the locks. */
    private function warnIfSilent(QueueEntry $e, QueueSetting $s): void
    {
        DB::transaction(function () use ($e, $s) {
            $locked = self::lockBoth($e);
            $idle = $locked && $locked->isOpen() ? self::idleSeconds($locked) : null;

            if ($idle === null || $idle < $s->silence_warn_seconds || $locked->silence_warned_at !== null) {
                return;
            }

            $locked->forceFill(['silence_warned_at' => now()])->save();
            SendQueueMessage::dispatch($locked->id, 'queue_silence_warning', [
                'minutes' => QueueWording::minutes((int) ceil(($s->silence_close_seconds - $idle) / 60)),
            ]);
            DB::afterCommit(fn () => SafeBroadcast::send(new QueueEntryUpdated($locked->fresh())));
        }, attempts: 3);
    }

    /** Auto-close, re-checking the silence under the locks (she may have written meanwhile). */
    private function closeIfSilent(QueueEntry $e, QueueSetting $s): void
    {
        DB::transaction(function () use ($e, $s) {
            $locked = self::lockBoth($e);
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
            $e = self::lockBoth($e);

            if ($e === null || ! $e->isOpen()) {
                return null;
            }

            return $this->rerouteLocked($e, $reason, $by, $priority, $trigger);
        }, attempts: 3);
    }

    /**
     * The reroute on an entry the caller holds locked and knows to be open. `$opts`:
     * `excluded_user_id` (the moderator the new entry must never go back to; else the old
     * entry's exclusion is carried), `tell_customer` (false: no «هنكمّل معاكي مع موظفة تانية»).
     *
     * @param  array{excluded_user_id?: int|null, tell_customer?: bool}  $opts
     */
    private function rerouteLocked(QueueEntry $e, string $reason, ?User $by, string $priority, string $trigger, array $opts = []): QueueEntry
    {
        $ticket = $e->ticket_no;
        $this->closeLocked($e, $reason, $by, [], null, route: false);
        $this->parkTicket($e);

        $new = QueueEntry::create($e->only(['conversation_id', 'customer_id', 'business_date', 'kind', 'bot_summary', 'is_test', 'last_customer_message_at', 'open_case_id']) + [
            'ticket_no' => $ticket, 'priority' => $priority, 'status' => 'waiting', 'shift_id' => $e->shift_id, 'enqueued_at' => now(),
            'reopened_from_entry_id' => $e->id, 'reopen_count' => $e->reopen_count, 'waiting_messages' => ['5' => true, '3' => true, '1' => true],
            'excluded_user_id' => $opts['excluded_user_id'] ?? $e->excluded_user_id,
            // Flow revision §6: her open case's owner is preferred again (the router never gives her
            // back to the excluded moderator, whoever that is).
            'reserved_user_id' => $this->caseOwnerOf($e),
        ]);

        $e->conversation?->forceFill([
            'assignee_id' => null, 'assigned_at' => null, 'queue_entry_id' => $new->id, 'handler' => Handler::Human, 'needs_human' => true,
        ])->save();

        if ($priority === 'returning' && ($opts['tell_customer'] ?? true)) {
            SendQueueMessage::dispatch($new->id, 'queue_reassigned', []);
        }

        // closeLocked() already pushes the conversation (read fresh after commit, so with the new entry).
        DB::afterCommit(fn () => SafeBroadcast::send(new QueueEntryUpdated($new->fresh())));
        app(QueueRouter::class)->runAfterCommit($trigger.' #'.$ticket);

        return $new;
    }

    /** The moderator who opened her still-open support case, when the switch is on; else null. */
    private function caseOwnerOf(QueueEntry $e): ?int
    {
        $case = $e->open_case_id !== null ? $e->openCase : null;

        if ($case === null || $case->status === 'closed' || $case->resolved_at !== null || $case->opened_by_id === null || ! QueueSetting::current()->case_follow_owner) {
            return null;
        }

        return (int) $case->opened_by_id;
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
        }

        // Spec 2026-09-30 §2: «خلصت» is the final close — hers, or a supervisor's on her behalf —
        // and it ends with the closing message. A case close sends its case number first. One
        // chain on the `outbound` queue keeps the two in that order (each is its own job, afterCommit).
        // Automatic close, transfer, escalation, no_reply and cancels send nothing new.
        //
        // Addendum C2 (the R1 override): the conversation then goes back to the bot, with the same
        // reset as «رجوع للبوت», on the conversation row this transaction holds locked. Her next
        // real message is the bot's; a human request is a new ticket (`returning` within
        // `return_priority_minutes`, see QueueService::enqueue()). The close is final: her return
        // never reverses it. No bot turn is started here.
        if (in_array($reason, QueueEntry::FINAL_CLOSE_REASONS, true) && $c) {
            $messages = $reason === 'case' && $e->support_case_id !== null
                ? [new SendQueueMessage($e->id, 'queue_case_opened', ['case_id' => $e->support_case_id])]
                : [];
            $messages[] = new SendQueueMessage($e->id, 'queue_closed_thanks', []);
            Bus::chain($messages)->onQueue('outbound')->dispatch();

            // §3: an inquiry / problem close is rated `review_delay_seconds` after the closing
            // message (not a case: Part 2 rates it when it is resolved). RatingService decides then;
            // the job is afterCommit and carries the close's token.
            if (in_array($reason, QueueEntry::RATED_CLOSE_REASONS, true)) {
                RequestRating::dispatch($e->id, $e->closed_at->toIso8601String())->delay(now()->addSeconds((int) $s->review_delay_seconds));
            }

            app(BotEngine::class)->resetToBot($c);
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

        // Attendance: a break or a check-out she asked for while this window was open starts /
        // completes once her last window is closed. After the commit, in its own transaction
        // (the member row is always the last lock), and never failing the close.
        if ($e->assigned_user_id !== null) {
            $userId = (int) $e->assigned_user_id;
            DB::afterCommit(fn () => rescue(fn () => app(ShiftService::class)->settleUser($userId), null, report: true));
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
