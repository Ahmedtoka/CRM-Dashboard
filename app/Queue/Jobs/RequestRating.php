<?php

namespace App\Queue\Jobs;

use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Queue\RatingService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The rating question of one inquiry / problem close, `review_delay_seconds` after it (spec
 * 2026-09-30 §3). `$closedAt` is the close's token: a job of another close of the row does
 * nothing.
 *
 * Picked up early (a sync queue ignores the delay; the owner made the delay longer) it releases
 * itself for the rest of the delay. Releases are not failures: `$tries = 0`, bounded by
 * `retryUntil()` and `$maxExceptions`.
 *
 * On the `outbound` queue like every customer message. A lost job means no rating for that close
 * (no safety net, plan ruling 18).
 */
class RequestRating implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $entryId, public readonly string $closedAt)
    {
        $this->onQueue('outbound');
        // Dispatched inside the close's transaction: never look for the close before it commits.
        $this->afterCommit();
    }

    /** The due time plus the hour after which the question is too late anyway (and five minutes of slack). */
    public function retryUntil(): DateTimeInterface
    {
        try {
            $closed = Carbon::parse($this->closedAt);
        } catch (Throwable) {
            $closed = now();
        }

        return $closed->addSeconds((int) QueueSetting::current()->review_delay_seconds)->addMinutes(RatingService::LATE_AFTER_MINUTES + 5);
    }

    public function handle(RatingService $ratings): void
    {
        $e = QueueEntry::query()->find($this->entryId);

        if ($e === null || $e->closed_at === null || $e->closed_at->toIso8601String() !== $this->closedAt) {
            return;
        }

        $due = RatingService::dueAt($e);

        if ($due->isFuture()) {
            $this->release(max(1, (int) ceil(now()->diffInSeconds($due))));

            return;
        }

        $ratings->request($e);
    }
}
