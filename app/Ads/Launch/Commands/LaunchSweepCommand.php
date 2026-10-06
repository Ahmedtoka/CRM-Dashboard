<?php

namespace App\Ads\Launch\Commands;

use App\Ads\Launch\LaunchService;
use App\Ads\Launch\MaterialStatus;
use Illuminate\Console\Command;

class LaunchSweepCommand extends Command
{
    protected $signature = 'ads:launch-sweep';

    protected $description = 'Launch housekeeping: day-6 warning, expiry after launch.expiry_days, reviewer re-point, material status refresh.';

    public function handle(LaunchService $launches, MaterialStatus $materials): int
    {
        $r = $launches->sweep();
        $this->info("Warned {$r['warned']}, expired {$r['expired']}, reassigned {$r['reassigned']}.");
        $this->info("Unstuck {$r['unstuck']}.");
        $this->info('Material statuses changed: '.$materials->refreshAll().'.');

        return self::SUCCESS;
    }
}
