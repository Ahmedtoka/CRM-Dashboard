<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Sync\SyncAdAccount;
use App\Models\AdsApiUsage;
use App\Models\AdsSyncRun;
use Illuminate\Console\Command;

/**
 * Ends sync runs whose worker died, and drops Meta usage telemetry older than 35 days.
 *
 * The cache lock `ads-sync-running:*` lives 3660 s, shorter than the sweep threshold (job timeout + 600 s = 4200 s),
 * so by the time a run is swept its lock has expired on its own: no lock release is needed.
 */
class SweepStuckRunsCommand extends Command
{
    protected $signature = 'ads:sweep-stuck-runs';

    protected $description = 'Mark sync runs stuck in running as error and prune old Meta usage rows';

    public function handle(): int
    {
        $cutoff = now()->subSeconds((new SyncAdAccount(0))->timeout + 600);

        AdsSyncRun::query()->where('status', 'running')->where('started_at', '<', $cutoff)->get()
            ->each(function (AdsSyncRun $run) {
                $run->update(['status' => 'error', 'error' => 'Worker stopped before the run finished', 'finished_at' => now()]);
                $this->line("Swept run #{$run->id} (account {$run->ad_account_id}, started {$run->started_at})");
            });

        AdsApiUsage::query()->where('recorded_at', '<', now()->subDays(35))->delete();

        return self::SUCCESS;
    }
}
