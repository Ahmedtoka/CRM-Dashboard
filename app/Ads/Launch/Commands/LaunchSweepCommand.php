<?php

namespace App\Ads\Launch\Commands;

use App\Ads\Launch\LaunchService;
use Illuminate\Console\Command;

class LaunchSweepCommand extends Command
{
    protected $signature = 'ads:launch-sweep';

    protected $description = 'Launch housekeeping: day-6 warning, expiry after launch.expiry_days, reviewer re-point.';

    public function handle(LaunchService $launches): int
    {
        $r = $launches->sweep();
        $this->info("Warned {$r['warned']}, expired {$r['expired']}, reassigned {$r['reassigned']}.");

        return self::SUCCESS;
    }
}
