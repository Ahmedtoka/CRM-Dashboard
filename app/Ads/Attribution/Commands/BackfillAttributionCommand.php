<?php

namespace App\Ads\Attribution\Commands;

use App\Ads\Attribution\AdCostPerOrder;
use App\Ads\Attribution\OrderAttribution;
use App\Ads\Audit\AdsAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Round 3 backfill: runs the attribution rules over a long window and re-checks every already attributed order in it
 * (the hourly run only re-checks the last 35 days), upgrading a weaker rule to a stronger one and filling the ad set and
 * the cost per order. It never replaces a stronger rule with a weaker one and never touches the platforms. Idempotent.
 */
class BackfillAttributionCommand extends Command
{
    protected $signature = 'ads:backfill-attribution {--days=120 : How many days back (by order date)}';

    protected $description = 'Re-run the order attribution rules and the ad cost per order over a long window (idempotent, local database only)';

    public function handle(OrderAttribution $attribution, AdCostPerOrder $cost): int
    {
        $days = max((int) $this->option('days'), 1);
        $to = CarbonImmutable::now();
        $from = $to->subDays($days)->startOfDay();

        $changed = $attribution->run($from, $to, force: false, reprocessDays: $days);
        $costs = $cost->run($from, $to);
        AdsAudit::record('orders.attribution_backfill', null, null, null, ['days' => $days, 'attributed' => $changed, 'cost_written' => $costs]);

        $this->info("Backfill over {$days} day(s): {$changed} order(s) attributed or upgraded, ad cost per order written on {$costs}.");

        return self::SUCCESS;
    }
}
