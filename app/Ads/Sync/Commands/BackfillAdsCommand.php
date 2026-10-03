<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

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
            // Same lock as SyncAdAccount's ShouldBeUnique, so a backfill never overlaps a queued sync of the account.
            $lock = Cache::lock(SyncAdAccount::lockKey($a->id), 3600);
            if (! $lock->get()) {
                $this->warn("Skipped {$a->name}: busy (a sync is already queued or running)");
                $failed++;

                continue;
            }
            try {
                $run = $sync->backfill($a, $days);
                if ($run?->status === 'error') {
                    $this->warn("Failed {$a->name}: ".AdsSyncService::scrub((string) $run->error));
                    $failed++;
                } else {
                    $this->line("Backfilled {$a->name} ({$days} days)");
                }
            } catch (Throwable $e) { // AdsApiException incl. RateLimited, or anything unexpected
                $this->warn("Failed {$a->name}: ".AdsSyncService::scrub($e->getMessage()));
                $failed++;
            } finally {
                $lock->release();
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
