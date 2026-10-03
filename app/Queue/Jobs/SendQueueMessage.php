<?php

namespace App\Queue\Jobs;

use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\QueueEntry;
use App\Queue\QueueScripts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

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

    public int $tries = 2;

    public function __construct(public readonly int $entryId, public readonly string $scriptKey, public readonly array $vars)
    {
        $this->onQueue('outbound');
        // The entry is created inside the handover's transaction: never look for it before it commits.
        $this->afterCommit();
    }

    public function handle(OutboundService $outbound, QueueScripts $scripts): void
    {
        $e = QueueEntry::with('conversation')->find($this->entryId);
        $text = $e ? $scripts->text($this->scriptKey, $this->vars) : null;

        if ($e === null || $e->conversation === null || $text === null || $this->stale($e)) {
            return;
        }

        try {
            $outbound->sendBot($e->conversation, $text);
        } catch (WindowClosedException) {
            Log::info('queue.window_closed', ['entry' => $e->id, 'script' => $this->scriptKey]);
        }
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
            return QueueEntry::query()->where('conversation_id', $e->conversation_id)->where('id', '>', $e->id)->exists();
        }

        return false;
    }
}
