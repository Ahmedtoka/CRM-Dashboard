<?php

namespace App\Ads\Control\Write\Jobs;

use App\Ads\Control\Write\WriteExecutor;
use App\Ads\Platforms\SecretScrubber;
use App\Models\AdWriteAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * One delayed retry of a confirmed Stop (2.1 rule 6). The worker never retries it ($tries = 1): the action's attempts
 * counter is the retry. The claim is a DB compare-and-set on (state, attempts, retry_at), so a duplicate delivery, a
 * worker restart or a lost cache can never send a second call for the same attempt (2.1 rule 8). Kill switch, caps and
 * restart lock do not apply to a Stop; scope and the account write switch are re-checked by the executor (rule 5).
 */
class RetryStopWrite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $actionId, public int $attempts) {}

    public function handle(WriteExecutor $executor): void
    {
        $claimed = AdWriteAction::whereKey($this->actionId)
            ->where('state', AdWriteAction::EXECUTING)
            ->where('attempts', $this->attempts)
            ->whereNotNull('retry_at')
            ->update(['retry_at' => null, 'updated_at' => now()]) === 1;
        if (! $claimed) {
            return; // a duplicate delivery, or already resolved
        }

        $x = AdWriteAction::find($this->actionId);
        if ($x !== null && $x->isStop()) {
            $executor->attempt($x);
        }
    }

    /**
     * The job died (timeout, worker crash, exception): a Stop still executing for this attempt (or the call it had just
     * sent) would otherwise wait for nobody. End it failed with the Ads Manager link; finish() notifies the holders.
     */
    public function failed(?Throwable $e = null): void
    {
        $x = AdWriteAction::find($this->actionId);
        if ($x === null || ! $x->isStop() || $x->state !== AdWriteAction::EXECUTING || ! in_array((int) $x->attempts, [$this->attempts, $this->attempts + 1], true)) {
            return;
        }
        if ($e !== null) {
            WriteExecutor::logUnexpected($e, $x);
        }
        app(WriteExecutor::class)->finish($x, AdWriteAction::FAILED, 'stop_failed', $e !== null ? SecretScrubber::scrub($e->getMessage()) : null,
            array_filter(['deep_link' => WriteExecutor::deepLink($x), 'retry_exhausted' => true], fn ($v) => $v !== null));
    }
}
