<?php

namespace App\Analytics\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention for latency_samples (fix round 1): it is written on every inbound,
 * outbound, list and queue-wait event once latency tracking is enabled, so it
 * needs the same pruning discipline as any other event log. Scheduled daily
 * at 04:00 Cairo (see AnalyticsServiceProvider).
 */
class PruneLatencyCommand extends Command
{
    protected $signature = 'crm:prune-latency {--days=7 : Delete samples older than this many days}';

    protected $description = 'Delete latency_samples rows older than --days, in chunks';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now()->subDays($days);

        $deleted = 0;

        do {
            $ids = DB::table('latency_samples')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            DB::table('latency_samples')->whereIn('id', $ids)->delete();
            $deleted += $ids->count();
        } while ($ids->count() === self::CHUNK);

        $this->info("deleted={$deleted}");

        return self::SUCCESS;
    }
}
