<?php

namespace App\Ads\Launch\Commands;

use App\Ads\Launch\LaunchService;
use App\Ads\Launch\MaterialStatus;
use App\Ads\Launch\PlatformArchive;
use Illuminate\Console\Command;

class LaunchSweepCommand extends Command
{
    protected $signature = 'ads:launch-sweep {--requeue-archives : Give failed platform archives another full round first}';

    protected $description = 'Launch housekeeping: day-6 warning, expiry after launch.expiry_days, reviewer re-point, material status refresh, dropped paused ads archived on the platform.';

    public function handle(LaunchService $launches, MaterialStatus $materials, PlatformArchive $archive): int
    {
        $r = $launches->sweep();
        $this->info("Warned {$r['warned']}, expired {$r['expired']}, reassigned {$r['reassigned']}.");
        $this->info("Unstuck {$r['unstuck']}.");
        $this->info('Material statuses changed: '.$materials->refreshAll().'.');
        if ($this->option('requeue-archives')) {
            $this->info('Archives re-queued: '.$archive->requeueFailed().'.');
        }
        $a = $archive->run();
        $this->info("Archived on the platform {$a['archived']}, waiting {$a['waiting']}, failed {$a['failed']}, skipped {$a['skipped']}.");

        return self::SUCCESS;
    }
}
