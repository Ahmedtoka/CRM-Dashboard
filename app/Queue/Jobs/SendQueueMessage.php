<?php

namespace App\Queue\Jobs;

use App\Inbox\OutboundService;
use App\Inbox\SendClaimRefused;
use App\Inbox\WindowClosedException;
use App\Models\QueueEntry;
use App\Models\QueueMessageSend;
use App\Queue\AfterCloseGuard;
use App\Queue\QueueScripts;
use App\Queue\WindowLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One queue script to the customer (enqueued, night, 5/3/1, apology…). Nothing is sent when the
 * entry is gone or the owner turned the script off; a closed reply window is logged, not retried.
 *
 * Stale messages are dropped at send time (final review of the flow revision): a lounge message —
 * the position update, the 5/3/1 countdown, the lounge apology — only while she is still
 * `waiting`; the moderator-delay apology only while her window is open and she still waits for
 * the reply it apologises for. A job that runs after she was called, or after the moderator
 * answered, says nothing. The closing message (spec 2026-09-30 §2) only while that close is still
 * her latest queue story: once a newer entry of the conversation exists («سعدنا بخدمتك» would
 * read as a goodbye to a customer who is back), it says nothing. Every other script goes out as before.
 *
 * Close-cycle hotfixes (2026-10-17, C1 #1):
 *  - every job is sent at most once: its `sendKey` (kept across the job's retries, `tries = 2`) is
 *    claimed in `queue_message_sends` in the same transaction that creates the Message, so a retry
 *    after the Message exists sends nothing (H-04);
 *  - the closing message is also stale once she wrote a real message or a colleague replied from
 *    the CRM since the close (`AfterCloseGuard`, D-01). Decided under the conversation + entry locks
 *    with a once-per-close claim (`closing_claimed_at`; a closed reply window or an empty text is
 *    decided before it, so they never claim), and checked again right before the platform call (`afterCloseOf`, H-03).
 */
class SendQueueMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Scripts that only make sense while she waits in the lounge. */
    public const WHILE_WAITING = ['queue_position_update', 'queue_left_5', 'queue_left_3', 'queue_left_1', 'queue_apology'];

    /** Scripts that only make sense while her open window waits for the moderator's reply. */
    public const WHILE_AWAITING_REPLY = ['queue_agent_delay_apology'];

    /** Scripts that only make sense while the close they end is her latest queue entry. */
    public const AFTER_CLOSE = ['queue_closed_thanks'];

    /** The closing chain waits this long after the close, so a webhook already on its way lands first (H-03). */
    public const CLOSING_SETTLE_SECONDS = 5;

    public int $tries = 2;

    /**
     * This job's send-once key, serialized with it: the same on every retry. Null on a job queued
     * before the key existed (in flight at deploy): it sends as before, without the claim.
     */
    public ?string $sendKey = null;

    public function __construct(public readonly int $entryId, public readonly string $scriptKey, public readonly array $vars)
    {
        $this->sendKey = (string) Str::uuid();
        $this->onQueue('outbound');
        // The entry is created inside the handover's transaction: never look for it before it commits.
        $this->afterCommit();
    }

    public function handle(OutboundService $outbound, QueueScripts $scripts): void
    {
        $e = QueueEntry::with('conversation')->find($this->entryId);
        $text = $e ? $scripts->text($this->scriptKey, $this->vars) : null;

        // A first look without locks (the common stale cases cost no lock); decided again in the claim.
        if ($e === null || $e->conversation === null || $text === null || $this->stale($e)) {
            return;
        }

        $closing = in_array($this->scriptKey, self::AFTER_CLOSE, true);

        try {
            $outbound->sendBot($e->conversation, $text, claim: fn () => $this->claim($e, $closing), afterCloseOf: $closing ? $e->id : null);
        } catch (SendClaimRefused) {
            Log::info('queue.message_skipped', ['entry' => $e->id, 'script' => $this->scriptKey]);
        } catch (WindowClosedException) {
            // Also an EmptyBotMessageException. Both are decided before the claim: nothing is claimed.
            Log::info('queue.window_closed', ['entry' => $e->id, 'script' => $this->scriptKey]);
        }
    }

    /**
     * Inside the transaction that creates the Message: this job's send-once claim, and for the
     * closing message the stale check again under the queue's lock order (conversation, then
     * entry) with the once-per-close claim. False: nothing is sent.
     */
    private function claim(QueueEntry $e, bool $closing): bool
    {
        if ($closing) {
            $locked = WindowLifecycle::lockBoth($e);

            if ($locked === null || $this->stale($locked)
                || QueueEntry::query()->whereKey($e->id)->whereNull('closing_claimed_at')->update(['closing_claimed_at' => now()]) !== 1) {
                return false;
            }
        }

        if ($this->sendKey === null) {
            return true;
        }

        if (QueueMessageSend::query()->where('send_key', $this->sendKey)->exists()) {
            return false;
        }

        QueueMessageSend::query()->create(['send_key' => $this->sendKey, 'queue_entry_id' => $e->id, 'script_key' => $this->scriptKey, 'created_at' => now()]);

        return true;
    }

    /** True when what the script says is no longer so (see the class docblock). */
    private function stale(QueueEntry $e): bool
    {
        if (in_array($this->scriptKey, self::WHILE_WAITING, true)) {
            return $e->status !== 'waiting';
        }

        if (in_array($this->scriptKey, self::WHILE_AWAITING_REPLY, true)) {
            // A reply clears both; a later message restarts the clock with no apology yet.
            return ! $e->isOpen() || $e->awaiting_reply_since === null || $e->apology_sent_at === null;
        }

        if (in_array($this->scriptKey, self::AFTER_CLOSE, true)) {
            return AfterCloseGuard::reason($e) !== null;
        }

        return false;
    }
}
