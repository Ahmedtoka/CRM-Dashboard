<?php

namespace App\Queue\Jobs;

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Queue\WindowLifecycle;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Runs `close_confirm_minutes` after an inquiry / problem close: the close stands unless the
 * customer came back meanwhile. `$closedAt` is the close's token — a job left over from an
 * earlier close of the same row (it cannot be reclosed today, but be safe) does nothing.
 *
 * Picked up before the confirmation time (a sync queue ignores the delay, the owner made the
 * window longer meanwhile) it releases itself for the remaining time. Releases are not failures:
 * the job has no attempt limit (`$tries = 0`, so a worker's `--tries=1` does not kill it) and is
 * bounded in time by `retryUntil()` and in exceptions by `$maxExceptions`.
 * `WindowLifecycle::confirmDue()` is the safety net when the job is lost.
 */
class ConfirmClose implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $entryId, public readonly string $closedAt)
    {
        $this->onQueue('bot');
        $this->afterCommit();
    }

    /** The confirmation time plus a day of slack. */
    public function retryUntil(): DateTimeInterface
    {
        try {
            $closed = Carbon::parse($this->closedAt);
        } catch (\Throwable) {
            $closed = now();
        }

        return $closed->addMinutes((int) QueueSetting::current()->close_confirm_minutes)->addDay();
    }

    public function handle(WindowLifecycle $lifecycle): void
    {
        $e = QueueEntry::query()->find($this->entryId);

        if ($e === null || $e->closed_at === null || $e->closed_at->toIso8601String() !== $this->closedAt) {
            return;
        }

        // Fired early (a sync queue ignores the delay, the window was made longer): the confirm
        // window is still running, so the close is not confirmed yet.
        $due = $e->closed_at->copy()->addMinutes((int) QueueSetting::current()->close_confirm_minutes);

        if ($due->isFuture()) {
            $this->release(max(1, (int) now()->diffInSeconds($due)));

            return;
        }

        $lifecycle->confirm($e);
    }
}
