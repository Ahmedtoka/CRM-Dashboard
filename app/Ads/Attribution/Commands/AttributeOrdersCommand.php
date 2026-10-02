<?php

namespace App\Ads\Attribution\Commands;

use App\Ads\Attribution\OrderAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AttributeOrdersCommand extends Command
{
    protected $signature = 'ads:attribute-orders {--days=35} {--force : Re-resolve orders that already have an attribution}';

    protected $description = 'Attribute recent orders to ads (utm ad id, utm campaign, inbox first touch)';

    public function handle(OrderAttribution $attribution): int
    {
        $days = max((int) $this->option('days'), 1);
        $to = CarbonImmutable::now();
        $count = $attribution->run($to->subDays($days)->startOfDay(), $to, (bool) $this->option('force'));

        $this->info("Attributed {$count} order(s).");

        return self::SUCCESS;
    }
}
