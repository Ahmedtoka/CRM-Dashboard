<?php

namespace App\Queue;

use App\Analytics\ActivityLogger;
use App\Bot\Flows\Sandbox\SandboxMode;
use App\Bot\WorkingHours;
use App\Enums\ActorType;
use App\Enums\Handler;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\QueueDay;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Queue\Data\HandoverContext;
use App\Queue\Events\QueueEntryUpdated;
use App\Queue\Jobs\SendQueueMessage;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The handover queue's front door: a customer the bot hands over takes a daily ticket
 * (`queue_days`), gets the «رقمك في الدور …» (or the night) message, and waits for the
 * router (Task 6) to give her a moderator window. When `queue_settings.enabled` is off
 * nothing here runs and the legacy «notify everyone» handover stays as it was.
 */
class QueueService
{
    public const TZ = 'Africa/Cairo';

    public function __construct(private readonly ActivityLogger $logger, private readonly WaitEstimator $estimator) {}

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

    /** Idempotent per conversation: an entry still waiting / called / active is returned as is. */
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
        // No shift open: overnight first — a returning customer at night gets the night message
        // and no ETA; her returning priority applies only while a shift is serving.
        $priority = match (true) {
            $shift === null && in_array($priority, [null, 'returning'], true) => 'overnight',
            $priority !== null => $priority,
            $c->return_priority_until !== null && $c->return_priority_until->isFuture() => 'returning',
            default => 'live',
        };
        $date = $this->businessDate();

        $entry = QueueEntry::create([
            'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'business_date' => $date, 'ticket_no' => $this->nextTicket($date),
            'kind' => in_array($ctx->kind, QueueEntry::KINDS, true) ? $ctx->kind : 'unknown', 'priority' => $priority, 'status' => 'waiting',
            'shift_id' => $shift?->id, 'enqueued_at' => now(), 'last_customer_message_at' => $c->last_customer_message_at, 'is_test' => (bool) $c->is_test,
            'bot_summary' => ['topic' => $ctx->topic, 'category' => $ctx->category, 'reason' => $ctx->reason, 'order_number' => $ctx->orderNumber, 'lines' => $ctx->summaryLines],
            'waiting_messages' => [],
        ]);
        $c->forceFill(['queue_entry_id' => $entry->id, 'assignee_id' => null, 'assigned_at' => null])->save();
        $this->logger->log(ActorType::System, null, ActivityLogger::QUEUE_ENQUEUE, null, $c, ['ticket' => $entry->ticket_no, 'priority' => $priority]);

        if ($priority === 'overnight') {
            if ($s->night_message_enabled) {
                SendQueueMessage::dispatch($entry->id, 'queue_night', ['ticket' => $entry->ticket_no, 'opening' => $this->nextOpeningPhrase()]);
            }
        } else {
            $entry->eta_seconds = $this->estimator->eta($entry);
            $entry->position_at_enqueue = $this->estimator->position($entry);
            $entry->waiting_messages = $this->estimator->alreadyPassed($entry->eta_seconds);
            $entry->save();
            SendQueueMessage::dispatch($entry->id, $priority === 'returning' ? 'queue_returning' : 'queue_enqueued', [
                'ticket' => $entry->ticket_no, 'eta_minutes' => max(1, (int) ceil($entry->eta_seconds / 60)), 'position' => $entry->position_at_enqueue,
            ]);
        }

        SafeBroadcast::send(new QueueEntryUpdated($entry));
        SafeBroadcast::send(new ConversationUpdated($c->fresh()));
        app(QueueRouter::class)->runAfterCommit('عميلة جديدة #'.$entry->ticket_no);

        return $entry;
    }

    /**
     * She wrote while queued / in a window: the silence clock restarts. The board and the
     * moderator's strip are told once the inbound message is committed (a rolled-back ingest
     * pushes nothing), for a waiting entry and for an open window alike.
     */
    public function customerMessage(Conversation $c): void
    {
        $e = $this->activeEntry($c);

        if ($e) {
            $e->forceFill(['last_customer_message_at' => now(), 'silence_warned_at' => null])->save();
            $id = $e->id;

            DB::afterCommit(function () use ($id) {
                if ($fresh = QueueEntry::query()->find($id)) {
                    SafeBroadcast::send(new QueueEntryUpdated($fresh));
                }
            });
        }
    }

    /**
     * A customer wrote on a conversation whose queue entry already ended (no waiting / open
     * entry). Judged on the LATEST terminal entry of any status (closed, cancelled, abandoned):
     *  - inside the return window (after an auto-close) or the confirm window (after an
     *    inquiry / problem close): back in the lounge as `returning`, preferring the same
     *    moderator; a close still awaiting confirmation is reversed, at most once;
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

            $returnWindow = $c->return_priority_until !== null && $c->return_priority_until->isFuture();
            $confirmWindow = WindowLifecycle::awaitsConfirmation($last)
                && $last->closed_at !== null && $last->closed_at->copy()->addMinutes($s->close_confirm_minutes)->isFuture();
            $returning = $returnWindow || $confirmWindow;

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
                $entry->forceFill(['reopened_from_entry_id' => $last->id, 'reserved_user_id' => $last->assigned_user_id, 'reopen_count' => $last->reopen_count + 1])->save();
            }

            if ($confirmWindow) {
                app(WindowLifecycle::class)->reverseClose($last);
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
