<?php

namespace App\Queue\Jobs;

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Queue\WindowLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs `close_confirm_minutes` after an inquiry / problem close: the close stands unless the
 * customer came back meanwhile. `$closedAt` is the close's token — a job left over from an
 * earlier close of the same row (it cannot be reclosed today, but be safe) does nothing.
 */
class ConfirmClose implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $entryId, public readonly string $closedAt)
    {
        $this->onQueue('bot');
        $this->afterCommit();
    }

    public function handle(WindowLifecycle $lifecycle): void
    {
        $e = QueueEntry::query()->find($this->entryId);

        if ($e === null || $e->closed_at === null || $e->closed_at->toIso8601String() !== $this->closedAt) {
            return;
        }

        // Fired early (a sync queue ignores the delay, a worker's clock may differ): the confirm
        // window is still running, so the close is not confirmed yet.
        $due = $e->closed_at->copy()->addMinutes((int) QueueSetting::current()->close_confirm_minutes);

        if ($due->isFuture()) {
            $this->release(max(1, (int) now()->diffInSeconds($due)));

            return;
        }

        $lifecycle->confirm($e);
    }
}
