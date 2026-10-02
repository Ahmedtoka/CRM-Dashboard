<?php

namespace App\Queue;

use App\Analytics\ActivityLogger;
use App\Bot\Flows\Sandbox\SandboxMode;
use App\Bot\WorkingHours;
use App\Enums\ActorType;
use App\Enums\Handler;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QueueDay;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\SupportCase;
use App\Queue\Data\HandoverContext;
use App\Queue\Events\QueueEntryUpdated;
use App\Queue\Jobs\SendQueueMessage;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The handover queue's front door: a customer the bot hands over takes a daily ticket
 * (`queue_days`), gets the «رقم تذكرتك #…» (or the night) message, and waits for the
 * router (Task 6) to give her a moderator window. When `queue_settings.enabled` is off
 * nothing here runs and the legacy «notify everyone» handover stays as it was.
 */
class QueueService
{
    public const TZ = 'Africa/Cairo';

    /**
     * On a conversation the bot handles, an acknowledgement stays quiet (no bot turn) for this long
     * after her last queue entry ended; later the bot answers a thanks like any message (spec 2026-09-30 §1).
     */
    public const ACK_QUIET_HOURS = 24;

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly WaitEstimator $estimator,
        private readonly QueueScripts $scripts,
    ) {}

    public function settings(): QueueSetting
    {
        return QueueSetting::current();
    }

    /** The open shift whose window contains now. */
    public function openShift(): ?Shift
    {
        return Shift::query()->where('status', 'open')->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderBy('starts_at')->first();
    }

    public function shouldQueue(): bool
    {
        $s = $this->settings();

        return $s->enabled && ($this->openShift() !== null || $s->night_message_enabled);
    }

    /**
     * The queue takes this handover (and sends its own message instead of the bot's transfer
     * sentence). Never inside a flow-designer sandbox run, where handovers are only recorded.
     */
    public function takesHandover(): bool
    {
        return ! SandboxMode::active() && $this->shouldQueue();
    }

    /**
     * The business day tickets are numbered in: the Cairo date. An overnight customer (after
     * midnight, before the first shift) is served when that same date's first shift opens, so
     * her ticket already belongs to it. Kept as its own method for the reports (Part 2).
     */
    public function businessDate(?CarbonInterface $at = null): string
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone(self::TZ)->toDateString();
    }

    /**
     * The next ticket of the day ('Y-m-d', Cairo business date).
     *
     * Lock order: the upsert is the FIRST statement touching the day's row, and it is a write —
     * it inserts the row (next_ticket 2, ticket 1 is ours) or bumps next_ticket on the unique
     * `date` key, taking the row's exclusive lock in one step. A concurrent caller blocks on that
     * same upsert until we commit, so nobody ever holds a shared lock it then has to upgrade
     * (the insert-ignore + SELECT … FOR UPDATE pattern deadlocks on MySQL with error 1213).
     * The SELECT … FOR UPDATE afterwards re-reads the row we already own, by the unique index.
     * `attempts: 3` retries a deadlock only when this is the outermost transaction; nested
     * inside the ingest transaction the outer one decides (acceptable: the webhook is retried).
     *
     * `queue_days.date` is always written and compared as the plain 'Y-m-d' string (query-builder
     * upsert, no Eloquent date cast), so the unique key matches on MySQL (DATE) and SQLite alike.
     */
    public function nextTicket(string $date): int
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        return DB::transaction(function () use ($date) {
            $stamp = now();
            QueueDay::query()->upsert(
                [['date' => $date, 'next_ticket' => 2, 'opened_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]],
                ['date'],
                ['next_ticket' => DB::raw('next_ticket + 1'), 'updated_at' => $stamp],
            );

            $day = QueueDay::query()->where('date', $date)->lockForUpdate()->firstOrFail();

            return (int) $day->next_ticket - 1;
        }, attempts: 3);
    }

    public function activeEntry(Conversation $c): ?QueueEntry
    {
        return QueueEntry::query()->where('conversation_id', $c->id)->whereIn('status', ['waiting', 'called', 'active'])->latest('id')->first();
    }

    /**
     * Her open support case — not closed and not resolved — newest first: on her customer
     * record, else (no customer) on this conversation (flow revision §6).
     */
    public function openCaseFor(Conversation $c): ?SupportCase
    {
        return SupportCase::query()->where('status', '!=', 'closed')->whereNull('resolved_at')
            ->when($c->customer_id !== null, fn ($q) => $q->where('customer_id', $c->customer_id), fn ($q) => $q->where('conversation_id', $c->id))
            ->latest('id')->first();
    }

    /**
     * Idempotent per conversation: an entry still waiting / called / active is returned as is.
     *
     * With no priority given, a customer whose latest queue entry closed within
     * `return_priority_minutes` comes back as `returning` (addendum C2: after «خلصت» the bot has her,
     * and her human request is a return), linked to that entry and reserved for its moderator
     * unless an open case already reserves her case owner. The same holds inside an auto-close's
     * return window (`return_priority_until`). Otherwise `live`; no shift open: `overnight`.
     */
    public function enqueue(Conversation $c, HandoverContext $ctx, ?string $priority = null): ?QueueEntry
    {
        $s = $this->settings();

        if (! $s->enabled) {
            return null;
        }

        if ($existing = $this->activeEntry($c)) {
            return $existing;
        }

        $shift = $this->openShift();
        // Her latest ended entry, when it closed within the return priority (null otherwise).
        $recent = $priority === null && $shift !== null ? $this->recentlyEnded($c, $s) : null;
        // No shift open: overnight first — a returning customer at night gets the night message
        // and no ETA; her returning priority applies only while a shift is serving.
        $priority = match (true) {
            $shift === null && in_array($priority, [null, 'returning'], true) => 'overnight',
            $priority !== null => $priority,
            $c->return_priority_until !== null && $c->return_priority_until->isFuture() => 'returning',
            $recent !== null => 'returning',
            default => 'live',
        };
        $date = $this->businessDate();
        // An open support case: the ticket carries it, and the moderator who opened it is preferred
        // (a reservation the router honours only while she is logged in with a free window).
        $case = $this->openCaseFor($c);
        $owner = $case !== null && $s->case_follow_owner && $case->opened_by_id !== null ? (int) $case->opened_by_id : null;

        $entry = QueueEntry::create([
            'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'business_date' => $date, 'ticket_no' => $this->nextTicket($date),
            'kind' => in_array($ctx->kind, QueueEntry::KINDS, true) ? $ctx->kind : 'unknown', 'priority' => $priority, 'status' => 'waiting',
            'shift_id' => $shift?->id, 'enqueued_at' => now(), 'last_customer_message_at' => $c->last_customer_message_at, 'is_test' => (bool) $c->is_test,
            'bot_summary' => ['topic' => $ctx->topic, 'category' => $ctx->category, 'reason' => $ctx->reason, 'order_number' => $ctx->orderNumber, 'lines' => $ctx->summaryLines],
            'waiting_messages' => [], 'open_case_id' => $case?->id, 'reserved_user_id' => $owner,
        ]);

        if ($priority === 'returning' && $recent !== null) {
            // Her case owner (above) comes first; else, as on a direct return, her last moderator.
            $entry->forceFill(['reopened_from_entry_id' => $recent->id, 'reserved_user_id' => $owner ?? $recent->assigned_user_id])->save();
        }

        $c->forceFill(['queue_entry_id' => $entry->id, 'assignee_id' => null, 'assigned_at' => null])->save();
        $this->logger->log(ActorType::System, null, ActivityLogger::QUEUE_ENQUEUE, null, $c, ['ticket' => $entry->ticket_no, 'priority' => $priority]);

        if ($priority === 'overnight') {
            if ($s->night_message_enabled) {
                SendQueueMessage::dispatch($entry->id, 'queue_night', ['ticket' => $entry->ticket_no, 'opening' => $this->nextOpeningPhrase()]);
            }
        } else {
            $eta = $this->estimator->eta($entry);
            $entry->eta_seconds = $eta;
            $entry->position_at_enqueue = $this->estimator->position($entry);
            $entry->waiting_messages = $eta === null ? [] : $this->estimator->alreadyPassed($eta);
            $entry->save();
            $ahead = max(0, $entry->position_at_enqueue - 1);

            if ($eta === null) {
                // Nobody who may take her is logged in: her ticket, and no minutes (flow revision §2).
                SendQueueMessage::dispatch($entry->id, 'queue_enqueued_no_eta', ['ticket' => $entry->ticket_no, 'ahead' => QueueWording::ahead($ahead), 'position' => $entry->position_at_enqueue]);
            } else {
                // Counts go out worded («قدامك عميلتين», «حوالي دقيقتين»): the scripts read them as whole phrases.
                SendQueueMessage::dispatch($entry->id, $priority === 'returning' ? 'queue_returning' : 'queue_enqueued', [
                    'ticket' => $entry->ticket_no, 'ahead' => QueueWording::ahead($ahead), 'eta_minutes' => QueueWording::minutes((int) ceil($eta / 60)), 'position' => $entry->position_at_enqueue,
                ]);
            }
        }

        SafeBroadcast::send(new QueueEntryUpdated($entry));
        SafeBroadcast::send(new ConversationUpdated($c->fresh()));
        app(QueueRouter::class)->runAfterCommit('عميلة جديدة #'.$entry->ticket_no);

        return $entry;
    }

    /**
     * She wrote while queued / in a window: the silence clock restarts. In an open window whose
     * assignee had answered everything, the moderator-reply clock starts now (flow revision §4.1).
     * A customer waiting in the lounge (not overnight) gets her ticket and who is ahead (§3).
     *
     * An acknowledgement (spec 2026-09-30 §1: thanks, emoji only, a sticker), while the queue is
     * on, asks for nothing:
     *  - no reply clock, so no apology and no hand-off;
     *  - no position reply in the lounge;
     *  - in an open window it only stamps `last_ack_at`, so the customer-silence clock keeps
     *    running from the moderator's last reply (`WindowLifecycle::silentSince()`).
     *
     * The board and the moderator's strip are told once the inbound message is committed (a
     * rolled-back ingest pushes nothing), for a waiting entry and for an open window alike.
     */
    public function customerMessage(Conversation $c, bool $acknowledgement = false): void
    {
        $e = $this->activeEntry($c);

        if (! $e) {
            return;
        }

        if ($acknowledgement && $this->settings()->enabled) {
            QueueEntry::query()->whereKey($e->id)->whereIn('status', QueueEntry::OPEN_STATUSES)->update(['last_ack_at' => now()]);
        } else {
            $e->forceFill(['last_customer_message_at' => now(), 'silence_warned_at' => null])->save();

            // The reply clock, by a conditional update: it reads the row as committed (not the
            // ingest's snapshot), so a reply of the assignee that committed a moment ago is never
            // missed, and a clock already running keeps its first moment. Waiting / closed: no clock.
            QueueEntry::query()->whereKey($e->id)->whereIn('status', QueueEntry::OPEN_STATUSES)->whereNull('awaiting_reply_since')
                ->update(['awaiting_reply_since' => now()]);

            if ($e->status === 'waiting' && $e->priority !== 'overnight' && $this->settings()->enabled) {
                $e->setRelation('conversation', $c);
                $this->sendPosition($e);
            }
        }

        $id = $e->id;

        DB::afterCommit(function () use ($id) {
            if ($fresh = QueueEntry::query()->find($id)) {
                SafeBroadcast::send(new QueueEntryUpdated($fresh));
            }
        });
    }

    /**
     * Spec 2026-09-30 §1: the queue's one hook for an inbound customer message (non-spam), called
     * once by the ingest inside its transaction. Returns true when the message asks for nothing
     * (it is "settled"): an acknowledgement (`$acknowledgement`, computed once by the ingest with
     * `Acknowledgement::matches()`) after her latest queue entry ended (any close: manual,
     * automatic, a hand-off), while the queue is on. The ingest then keeps it in the thread, but:
     *  - it is not counted unread;
     *  - no queue hook runs: no ticket;
     *  - no bot turn (decided here, before the bot hand-off, also on a bot-handled conversation).
     *
     * On a conversation a person handles (an automatic close) this holds with no time limit; on
     * one the bot handles (a manual close hands it back, or «رجوع للبوت») only for
     * `ACK_QUIET_HOURS` after the close, after which the bot answers a thanks like any message.
     *
     * Otherwise it runs the queue hook as before: `customerReturned()` (she came back inside the
     * return window of an auto-close, or after it on a human-handled chat), else `customerMessage()`.
     *
     * The decision is taken under the CONVERSATION lock with a locking read of her latest entry,
     * so a close («خلصت» or the tick's auto-close) that commits while the message is being
     * ingested is seen: an acknowledgement is then settled, never re-queued, never a reversal.
     * Queue off: no lock, nothing settled, the hook runs as it always did.
     */
    public function settles(Conversation $c, Message $m, bool $acknowledgement = false): bool
    {
        // A bot-handled chat's real message: nothing for the queue (no query, the common case).
        if ($c->handler !== Handler::Human && ! $acknowledgement) {
            return false;
        }

        if (! $this->settings()->enabled) {
            if ($c->handler === Handler::Human && ! $this->customerReturned($c)) {
                $this->customerMessage($c);
            }

            return false;
        }

        return DB::transaction(function () use ($c, $acknowledgement) {
            $handler = Conversation::query()->whereKey($c->id)->lockForUpdate()->first(['id', 'handler'])?->handler;
            $last = QueueEntry::query()->where('conversation_id', $c->id)->latest('id')->lockForUpdate()->first(['id', 'status', 'closed_at']);
            $ended = $last !== null && in_array($last->status, QueueEntry::TERMINAL_STATUSES, true);

            if ($acknowledgement && $ended && $this->quietAfter($last, $handler)) {
                return true;
            }

            if ($handler === Handler::Human && ! $this->customerReturned($c)) {
                $this->customerMessage($c, $acknowledgement);
            }

            return false;
        }, attempts: 3);
    }

    /**
     * After her latest entry ended, an acknowledgement stays quiet: with no time limit while a
     * person handles the chat, for `ACK_QUIET_HOURS` after the close once the bot does.
     */
    private function quietAfter(QueueEntry $last, ?Handler $handler): bool
    {
        return $handler === Handler::Human
            || ($last->closed_at !== null && $last->closed_at->greaterThan(now()->subHours(self::ACK_QUIET_HOURS)));
    }

    /** Her latest queue entry when it has ended and closed within `return_priority_minutes`. */
    private function recentlyEnded(Conversation $c, QueueSetting $s): ?QueueEntry
    {
        $last = QueueEntry::query()->where('conversation_id', $c->id)->latest('id')->first(['id', 'status', 'closed_at', 'assigned_user_id']);

        return $last !== null && in_array($last->status, QueueEntry::TERMINAL_STATUSES, true)
            && $last->closed_at !== null && $last->closed_at->copy()->addMinutes($s->return_priority_minutes)->isFuture()
            ? $last : null;
    }

    /**
     * Her ticket, who is ahead of her in the lounge and, when there is one, the estimate — at most
     * once per `waiting_update_seconds` per entry, claimed by a conditional update so two messages
     * that arrive together send one; the messages in between get no reply. The enqueue message
     * does not count: her first message after it is answered.
     */
    private function sendPosition(QueueEntry $e): void
    {
        $cut = now()->subSeconds((int) $this->settings()->waiting_update_seconds);
        $claimed = QueueEntry::query()->whereKey($e->id)->where('status', 'waiting')
            ->where(fn ($q) => $q->whereNull('position_update_sent_at')->orWhere('position_update_sent_at', '<=', $cut))
            ->update(['position_update_sent_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $eta = $this->estimator->eta($e);
        $sentence = $eta === null ? '' : (string) ($this->scripts->text('queue_eta_sentence', ['minutes' => QueueWording::minutes((int) ceil($eta / 60))]) ?? '');

        SendQueueMessage::dispatch($e->id, 'queue_position_update', [
            'ticket' => $e->ticket_no,
            'ahead' => QueueWording::ahead($this->estimator->position($e) - 1),
            'eta_sentence' => $sentence,
        ]);
    }

    /**
     * A customer wrote on a conversation whose queue entry already ended (no waiting / open
     * entry). Judged on the LATEST terminal entry of any status (closed, cancelled, abandoned):
     *  - inside the return window (after an auto-close): back in the lounge as `returning`,
     *    preferring the same moderator. A manual close is final (addendum C2, the R1 override):
     *    her return never reverses it, the confirm window plays no part here, and `ConfirmClose`
     *    confirms it on schedule (after «خلصت» the bot has her, so this runs only when a person
     *    took the chat back by hand);
     *  - otherwise, on a human-handled conversation while the queue takes handovers
     *    (`takesHandover()`): a new entry so somebody is called — `returning` within
     *    `return_priority_minutes` of that last close (whatever its reason, a case included),
     *    else `live`. Outside shift hours both follow the overnight path.
     * She gets the usual queue message of the path. Returns true when an entry was created.
     *
     * Lock order: conversation, then entries. Two messages arriving together are serialised on
     * the conversation row and the second one finds the first one's entry (locking read).
     */
    public function customerReturned(Conversation $c): bool
    {
        $s = $this->settings();

        if (! $s->enabled) {
            return false;
        }

        return DB::transaction(function () use ($c, $s) {
            Conversation::query()->whereKey($c->id)->lockForUpdate()->first(['id']);

            if (QueueEntry::query()->where('conversation_id', $c->id)->whereIn('status', ['waiting', 'called', 'active'])->lockForUpdate()->first(['id'])) {
                return false;
            }

            $last = QueueEntry::query()->where('conversation_id', $c->id)->whereIn('status', QueueEntry::TERMINAL_STATUSES)
                ->latest('id')->lockForUpdate()->first();

            if (! $last) {
                return false;
            }

            $returning = $c->return_priority_until !== null && $c->return_priority_until->isFuture();

            if (! $returning) {
                // After the windows: only a conversation a human handles, only while the queue takes handovers.
                if ($c->handler !== Handler::Human || ! $this->takesHandover()) {
                    return false;
                }

                $returning = $last->closed_at !== null && $last->closed_at->copy()->addMinutes($s->return_priority_minutes)->isFuture();
            }

            $summary = $last->bot_summary ?? [];
            $ctx = new HandoverContext('returning', (string) ($summary['category'] ?? 'returning'), 'medium', $summary['topic'] ?? null, $summary['lines'] ?? [], $last->kind, $summary['order_number'] ?? null);
            $c->forceFill(['handler' => Handler::Human, 'needs_human' => true])->save();
            // null = live, or overnight when no shift is open (enqueue() decides).
            $entry = $this->enqueue($c, $ctx, $returning ? 'returning' : null);

            if ($entry === null) {
                return false;
            }

            if ($returning) {
                // Her case owner (set by enqueue) comes first; else, as before, her last moderator.
                $entry->forceFill(['reopened_from_entry_id' => $last->id, 'reserved_user_id' => $entry->reserved_user_id ?? $last->assigned_user_id, 'reopen_count' => $last->reopen_count + 1])->save();
            }

            return true;
        }, attempts: 3);
    }

    /** «10 الصبح»: the first shift template's start (the script itself says «الساعة {opening}»). */
    private function nextOpeningPhrase(): string
    {
        $from = (string) ($this->settings()->shiftTemplates()[0]['from'] ?? '10:00');

        try {
            return WorkingHours::clock(CarbonImmutable::createFromFormat('H:i', $from, self::TZ));
        } catch (\Throwable) {
            return $from;
        }
    }
}
