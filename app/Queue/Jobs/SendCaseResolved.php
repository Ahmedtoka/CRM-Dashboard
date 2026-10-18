<?php

namespace App\Queue\Jobs;

use App\Bot\Flows\FlowState;
use App\Inbox\OutboundService;
use App\Inbox\SendClaimRefused;
use App\Inbox\WindowClosedException;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\QueueMessageSend;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Queue\QueueScripts;
use App\Queue\QueueService;
use App\Queue\RatingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * «تم حل طلبك رقم N» (close-cycle-FINAL §6.3, D-04, D-22, Q3, Q11, RC-14), for a case a moderator
 * opened from a window and for one the bot collected alike.
 *
 * Only while the chat is the bot's or closed — no window, no place in the lounge, no bot flow
 * running — and its reply window is open, never on a test chat. While she is busy elsewhere the
 * job comes back every RETRY_MINUTES for up to GIVE_UP_HOURS after the resolution, then gives up.
 * Q3: a case opened from a window also asks for the rating of that window (its own rules: not asked
 * for that window yet, not asked of her in 24 hours, the queue on), with the five buttons, in the
 * same message. Sent once (send-once key, and the rating claim) inside the Message's transaction.
 */
class SendCaseResolved implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const RETRY_MINUTES = 10;

    public const GIVE_UP_HOURS = 24;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public string $sendKey;

    public function __construct(public readonly int $caseId)
    {
        $this->sendKey = (string) Str::uuid();
        $this->onQueue('outbound');
        $this->afterCommit();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(self::GIVE_UP_HOURS + 1);
    }

    public function handle(OutboundService $outbound, QueueScripts $scripts, RatingService $ratings): void
    {
        $case = SupportCase::query()->find($this->caseId);
        $c = $case?->conversation_id !== null ? Conversation::query()->find($case->conversation_id) : null;

        if ($case === null || $c === null || $case->status !== 'resolved' || $case->resolved_at === null
            || $case->resolved_at->lessThan(now()->subHours(self::GIVE_UP_HOURS)) || ((bool) $c->is_test && ! $c->isLoadTest())) {
            return;
        }

        if (app(QueueService::class)->activeEntry($c) !== null || FlowState::flow($c) !== null) {
            $this->release(self::RETRY_MINUTES * 60);

            return;
        }

        $entry = $case->queue_entry_id !== null ? QueueEntry::query()->with(['conversation', 'assignee'])->find($case->queue_entry_id) : null;
        $rate = $entry !== null && $this->askable($entry, $ratings);
        $text = $scripts->text('queue_case_resolved', ['case_id' => $case->id]);
        $ask = $rate ? $scripts->text('queue_review_ask', ['name' => trim(explode(' ', trim((string) $entry->assignee?->name))[0] ?? '')]) : null;
        $rate = $rate && $ask !== null;
        $body = implode("\n\n", array_filter([$text, $rate ? $ask : null]));

        if (trim($body) === '') {
            return;
        }

        try {
            $m = $outbound->sendBot($c, $body, 0, false, $rate ? RatingService::buttons($entry) : [], claim: fn () => $this->claim($c, $entry, $rate));
        } catch (SendClaimRefused|WindowClosedException) {
            Log::info('queue.case_resolved_skipped', ['case' => $case->id]);

            return;
        }

        if ($rate) {
            QueueEntry::query()->whereKey($entry->id)->update(['review_message_id' => $m->id]);
        }
    }

    private function askable(QueueEntry $e, RatingService $ratings): bool
    {
        $c = $e->conversation;

        return QueueSetting::current()->enabled && $c !== null && $e->review_requested_at === null && $e->assigned_user_id !== null
            && ! (($e->is_test || (bool) $c->is_test) && ! $c->isLoadTest()) && ! $ratings->askedRecently($e);
    }

    private function claim(Conversation $c, ?QueueEntry $e, bool $rate): bool
    {
        Conversation::query()->whereKey($c->id)->lockForUpdate()->first(['id']);

        if (app(QueueService::class)->activeEntry($c) !== null || QueueMessageSend::query()->where('send_key', $this->sendKey)->exists()) {
            return false;
        }

        if ($rate && QueueEntry::query()->whereKey($e->id)->whereNull('review_requested_at')->update(['review_requested_at' => now()]) !== 1) {
            return false;
        }

        QueueMessageSend::query()->create(['send_key' => $this->sendKey, 'queue_entry_id' => $e?->id ?? 0, 'script_key' => 'queue_case_resolved', 'created_at' => now()]);

        return true;
    }
}
