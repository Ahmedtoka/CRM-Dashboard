<?php

namespace App\Cases\Returns;

use App\Bot\Flows\FlowState;
use App\Inbox\EmptyBotMessageException;
use App\Inbox\OutboundService;
use App\Inbox\SendClaimRefused;
use App\Inbox\WindowClosedException;
use App\Models\Conversation;
use App\Models\SupportCaseEvent;
use App\Queue\QueueScripts;
use App\Queue\QueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Tells the customer what happened to her return/exchange at one lifecycle step (round 2026-10-24), with the
 * step's ReturnScripts text (editable from Settings → معرفة البوت; switched off = nothing is sent).
 *
 * Like SendCaseResolved: only while the chat is the bot's or closed — a moderator window or a running bot flow
 * makes it come back every RETRY_MINUTES for up to GIVE_UP_HOURS — never on a test chat, and never twice for one
 * event. A closed reply window (WhatsApp's 24 hours) is recorded on the event (`notify_error`) so the moderator
 * sees the customer was not told and can write herself.
 */
class SendReturnUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const RETRY_MINUTES = 10;

    public const GIVE_UP_HOURS = 24;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('outbound');
        $this->afterCommit();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(self::GIVE_UP_HOURS + 1);
    }

    public function handle(OutboundService $outbound, QueueScripts $scripts): void
    {
        $event = SupportCaseEvent::query()->with('supportCase')->find($this->eventId);
        $case = $event?->supportCase;
        $c = $case?->conversation_id !== null ? Conversation::query()->find($case->conversation_id) : null;

        if ($event === null || $case === null || $c === null || $event->script_key === null || $event->customer_notified_at !== null
            || $event->notify_error !== null || $event->happened_at->lessThan(now()->subHours(self::GIVE_UP_HOURS))) {
            return;
        }

        if ((bool) $c->is_test && ! $c->isLoadTest()) {
            $event->forceFill(['notify_error' => 'test_chat'])->save();

            return;
        }

        // She is with a moderator or inside a bot flow: not now.
        if (app(QueueService::class)->activeEntry($c) !== null || FlowState::flow($c) !== null) {
            $this->release(self::RETRY_MINUTES * 60);

            return;
        }

        $vars = (array) (($event->meta ?? [])['script_vars'] ?? []);
        $body = $scripts->text($event->script_key, $vars + ['case_id' => $case->id]);

        if ($body === null || trim($body) === '') {
            $event->forceFill(['notify_error' => 'script_off'])->save();

            return;
        }

        try {
            $outbound->sendBot($c, $body);
        } catch (WindowClosedException|SendClaimRefused|EmptyBotMessageException $e) {
            Log::info('returns.customer_not_told', ['case' => $case->id, 'event' => $event->id, 'why' => $e::class]);
            $event->forceFill(['notify_error' => $e instanceof WindowClosedException ? 'window_closed' : 'refused'])->save();

            return;
        }

        $event->forceFill(['customer_notified_at' => now()])->save();
    }
}
