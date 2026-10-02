<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Platforms\AdsApiException;
use App\Ads\Sync\AdsSyncService;
use App\Models\AdAccount;
use Illuminate\Console\Command;

class BackfillAdsCommand extends Command
{
    protected $signature = 'ads:backfill {--account= : Ad account id} {--days=90}';

    protected $description = 'Backfill ad metrics in 30-day chunks, newest first';

    public function handle(AdsSyncService $sync): int
    {
        $days = max((int) $this->option('days'), 1);
        $accounts = AdAccount::query()->where('is_active', true)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))->get();
        $failed = 0;

        // Runs inline so the chunks stay in order and the command shows progress.
        foreach ($accounts as $a) {
            try {
                $run = $sync->backfill($a, $days);
                if ($run?->status === 'error') {
                    $this->warn("Failed {$a->name}: ".AdsSyncService::scrub((string) $run->error));
                    $failed++;
                } else {
                    $this->line("Backfilled {$a->name} ({$days} days)");
                }
            } catch (AdsApiException $e) { // includes RateLimited
                $this->warn("Failed {$a->name}: ".AdsSyncService::scrub($e->getMessage()));
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
