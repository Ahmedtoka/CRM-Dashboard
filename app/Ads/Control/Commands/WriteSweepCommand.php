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
 * within the same 3-call bound; past the bound it ends failed with the Ads Manager link and a notice to the holders.
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

        $rows = AdWriteAction::where('state', AdWriteAction::EXECUTING)->where('to_status', 'paused')
            ->whereNotNull('retry_at')->where('retry_at', '<', $overdue)->orderBy('id')->get();

        foreach ($rows as $x) {
            if ($x->attempts < $max) {
                $claimed = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::EXECUTING)->where('attempts', $x->attempts)
                    ->where('retry_at', '<', $overdue)->update(['retry_at' => now(), 'updated_at' => now()]) === 1;
                if (! $claimed) {
                    continue;
                }
                AdsAudit::record('write.retry_redispatched', $x, null, null, ['public_id' => $x->public_id, 'attempts' => $x->attempts]);
                RetryStopWrite::dispatch($x->id, (int) $x->attempts)->onQueue((string) config('crm.ads.write.retry_queue', 'commerce'));
                $redispatched++;

                continue;
            }

            $x = $executor->finish($x, AdWriteAction::FAILED, $x->error_code ?: 'stop_failed', $x->error_message,
                array_filter(['deep_link' => WriteExecutor::deepLink($x), 'retry_exhausted' => true], fn ($v) => $v !== null));
            if ($x->state === AdWriteAction::FAILED) {
                $failed++;
            }
        }

        if ($redispatched + $failed > 0) {
            $this->info("Stop retries: {$redispatched} re-dispatched, {$failed} ended failed.");
        }

        return self::SUCCESS;
    }
}
