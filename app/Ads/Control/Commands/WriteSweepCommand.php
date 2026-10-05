<?php

namespace App\Ads\Control\Commands;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\Write\Jobs\RetryStopWrite;
use App\Ads\Control\Write\WriteExecutor;
use App\Models\AdWriteAction;
use Illuminate\Console\Command;

/**
 * Liveness of confirmed Stop retries (2.1 rule 6), scheduled every 5 minutes. Read-only toward the platform: it never
 * calls Meta itself. A Stop whose retry is more than 5 minutes overdue (a lost dispatch) gets its RetryStopWrite again,
 * within the same 3-call bound and at most stop_sweep_max_redispatches times; past either bound, or when the Stop has
 * been executing longer than the whole retry window plus a margin (a dead worker), it ends failed with the Ads Manager
 * link and a notice to the holders. A dead worker therefore always ends in a notice, never a silent hang.
 * Claims are DB compare-and-set on (state, attempts, retry_at), like the job's own.
 */
class WriteSweepCommand extends Command
{
    public const OVERDUE_MINUTES = 5;

    protected $signature = 'ads:write-sweep';

    protected $description = 'Re-dispatch overdue retries of confirmed Stops, or end them failed with a notice';

    public function handle(WriteExecutor $executor): int
    {
        $overdue = now()->subMinutes(self::OVERDUE_MINUTES);
        $max = (int) config('crm.ads.write.stop_retry_attempts', 3);
        $redispatched = 0;
        $failed = 0;

        $maxRedispatches = (int) config('crm.ads.write.stop_sweep_max_redispatches', 3);
        $window = max(0, $max - 1) * (int) config('crm.ads.write.stop_retry_max_wait_seconds', 1800)
            + (int) config('crm.ads.write.stop_sweep_margin_seconds', 600);
        $tooOld = now()->subSeconds($window);

        // Give up first: a Stop executing longer than the whole retry window (a dead worker, a job lost mid-attempt) ends
        // failed with a notice instead of hanging silently.
        $stuck = AdWriteAction::where('state', AdWriteAction::EXECUTING)->where('to_status', 'paused')
            ->whereNotNull('executing_at')->where('executing_at', '<', $tooOld)->orderBy('id')->get();
        foreach ($stuck as $x) {
            $failed += $this->giveUp($executor, $x) ? 1 : 0;
        }

        $rows = AdWriteAction::where('state', AdWriteAction::EXECUTING)->where('to_status', 'paused')
            ->whereNotNull('retry_at')->where('retry_at', '<', $overdue)->orderBy('id')->get();

        foreach ($rows as $x) {
            $redispatches = (int) ($x->outcome['redispatches'] ?? 0);
            if ($x->attempts < $max && $redispatches < $maxRedispatches) {
                $claimed = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::EXECUTING)->where('attempts', $x->attempts)
                    ->where('retry_at', '<', $overdue)->update(['retry_at' => now(), 'updated_at' => now(),
                        'outcome' => json_encode(array_merge($x->outcome ?? [], ['redispatches' => $redispatches + 1]))]) === 1;
                if (! $claimed) {
                    continue;
                }
                AdsAudit::record('write.retry_redispatched', $x, null, null, ['public_id' => $x->public_id, 'attempts' => $x->attempts, 'redispatches' => $redispatches + 1]);
                RetryStopWrite::dispatch($x->id, (int) $x->attempts)->onQueue((string) config('crm.ads.write.retry_queue', 'commerce'));
                $redispatched++;

                continue;
            }

            $failed += $this->giveUp($executor, $x) ? 1 : 0;
        }

        if ($redispatched + $failed > 0) {
            $this->info("Stop retries: {$redispatched} re-dispatched, {$failed} ended failed.");
        }

        return self::SUCCESS;
    }

    /** Ends an executing Stop failed with retry_exhausted (finish() sends the notice with the Ads Manager link). */
    private function giveUp(WriteExecutor $executor, AdWriteAction $x): bool
    {
        $x = $executor->finish($x, AdWriteAction::FAILED, $x->error_code ?: 'stop_failed', $x->error_message,
            array_filter(['deep_link' => WriteExecutor::deepLink($x), 'retry_exhausted' => true], fn ($v) => $v !== null));

        return $x->state === AdWriteAction::FAILED;
    }
}
