<?php

namespace App\Ads\Attribution\Commands;

use App\Ads\Attribution\AdCostPerOrder;
use App\Ads\Attribution\AttributionBackup;
use App\Ads\Attribution\OrderAttribution;
use App\Ads\Audit\AdsAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AttributeOrdersCommand extends Command
{
    protected $signature = 'ads:attribute-orders {--days=35} {--force : Re-resolve orders that already have an attribution (snapshots them first)}';

    protected $description = 'Attribute recent orders to ads (chat referral, utm ad id, utm ad set id, utm campaign) and compute the ad cost per order';

    public function handle(OrderAttribution $attribution, AttributionBackup $backup, AdCostPerOrder $cost): int
    {
        $days = max((int) $this->option('days'), 1);
        $to = CarbonImmutable::now();
        $from = $to->subDays($days)->startOfDay();
        $force = (bool) $this->option('force');

        // R-19: a forced run rewrites orders.ad_*; snapshot the window first, or do nothing.
        if ($force) {
            try {
                ['table' => $table, 'rows' => $rows] = $backup->create($from, $to);
            } catch (\Throwable $e) {
                report($e);
                $this->error('Backup failed; nothing was re-attributed.');

                return self::FAILURE;
            }
            AdsAudit::record('orders.attribution_backup_created', meta: ['table' => $table, 'rows' => $rows, 'from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()]);
            $this->info("Backup {$table}: {$rows} order(s). Restore with: php artisan ads:attribution-restore {$table} --force");
        }

        $count = $attribution->run($from, $to, $force);

        $this->info("Attributed {$count} order(s).");

        // Round 3: «تكلفة الإعلان لكل أوردر» follows every attribution run over the same window.
        $costs = $cost->run($from, $to);
        $this->info("Ad cost per order written on {$costs} order(s).");

        return self::SUCCESS;
    }
}
