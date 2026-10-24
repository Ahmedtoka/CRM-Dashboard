<?php

namespace App\Ads\Materials\Commands;

use App\Ads\Materials\StockWatcher;
use Illuminate\Console\Command;

class StockWatchCommand extends Command
{
    protected $signature = 'ads:stock-watch';

    protected $description = 'Flag running ad materials whose product is out of stock and notify their media buyer and supervisors';

    public function handle(StockWatcher $watcher): int
    {
        $r = $watcher->run();
        $this->info("Flagged {$r['flagged']}, cleared {$r['cleared']}.");
        $h = $watcher->holds();
        $this->info("Held {$h['held']}, released {$h['released']}.");
        $l = $watcher->low();
        $this->info("Low stock: flagged {$l['flagged']}, cleared {$l['cleared']}.");

        return self::SUCCESS;
    }
}
