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

    /** The next ticket of the day, under a row lock (the day's row is created on first use). */
    public function nextTicket(string $date): int
    {
        return DB::transaction(function () use ($date) {
            $stamp = now();
            // Two first-of-the-day handovers at once: only one row is ever created.
            QueueDay::query()->insertOrIgnore([
                'date' => (new QueueDay)->fromDateTime($date), 'next_ticket' => 1, 'opened_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);

            $day = QueueDay::query()->whereDate('date', $date)->lockForUpdate()->firstOrFail();
            $n = (int) $day->next_ticket;
            $day->update(['next_ticket' => $n + 1]);

            return $n;
        });
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
        $priority ??= match (true) {
            $c->return_priority_until !== null && $c->return_priority_until->isFuture() => 'returning',
            $shift === null => 'overnight',
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

    /** She wrote while queued / in a window: the silence clock restarts. */
    public function customerMessage(Conversation $c): void
    {
        $e = $this->activeEntry($c);

        if ($e) {
            $e->forceFill(['last_customer_message_at' => now(), 'silence_warned_at' => null])->save();

            if ($e->status === 'waiting') {
                SafeBroadcast::send(new QueueEntryUpdated($e));
            }
        }
    }

    /** A customer wrote after an auto-close inside the return window, or after a manual close inside the confirm window. */
    public function customerReturned(Conversation $c): bool
    {
        $s = $this->settings();

        if (! $s->enabled || $this->activeEntry($c)) {
            return false;
        }

        $last = QueueEntry::query()->where('conversation_id', $c->id)->where('status', 'closed')->latest('id')->first();

        if (! $last) {
            return false;
        }

        $returnWindow = $c->return_priority_until !== null && $c->return_priority_until->isFuture();
        $confirmWindow = in_array($last->close_reason, ['inquiry', 'problem'], true) && $last->confirmed_at === null
            && $last->closed_at !== null && $last->closed_at->copy()->addMinutes($s->close_confirm_minutes)->isFuture();

        if (! $returnWindow && ! $confirmWindow) {
            return false;
        }

        $summary = $last->bot_summary ?? [];
        $ctx = new HandoverContext('returning', (string) ($summary['category'] ?? 'returning'), 'medium', $summary['topic'] ?? null, $summary['lines'] ?? [], $last->kind, $summary['order_number'] ?? null);
        $c->forceFill(['handler' => Handler::Human, 'needs_human' => true])->save();
        $entry = $this->enqueue($c, $ctx, 'returning');

        if ($entry === null) {
            return false;
        }

        $entry->forceFill(['reopened_from_entry_id' => $last->id, 'reserved_user_id' => $last->assigned_user_id, 'reopen_count' => $last->reopen_count + 1])->save();

        if ($confirmWindow) {
            app(WindowLifecycle::class)->reverseClose($last); // Task 7
        }

        return true;
    }

    /** «الساعة 10 الصبح»: the first shift template's start. */
    private function nextOpeningPhrase(): string
    {
        $from = (string) ($this->settings()->shiftTemplates()[0]['from'] ?? '10:00');

        try {
            return 'الساعة '.WorkingHours::clock(CarbonImmutable::createFromFormat('H:i', $from, self::TZ));
        } catch (\Throwable) {
            return 'الساعة '.$from;
        }
    }
}
