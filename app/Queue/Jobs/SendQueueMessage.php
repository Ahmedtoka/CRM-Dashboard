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
 */
class SendQueueMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

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

        if ($e === null || $e->conversation === null || $text === null) {
            return;
        }

        try {
            $outbound->sendBot($e->conversation, $text);
        } catch (WindowClosedException) {
            Log::info('queue.window_closed', ['entry' => $e->id, 'script' => $this->scriptKey]);
        }
    }
}
