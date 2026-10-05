<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\HistoryWindow;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class BackfillAdsCommand extends Command
{
    protected $signature = 'ads:backfill {--account= : Ad account id} {--external= : Ad account id on the platform, e.g. act_123 (comma list)} {--days=90} {--from= : First day to backfill (Y-m-d); earlier than the history start is clamped}
        {--queue : Hand it to the commercelong worker, which retries every 15 minutes while Meta says «retry later»}';

    protected $description = 'Backfill ad metrics in 30-day chunks, newest first';

    public function handle(AdsSyncService $sync): int
    {
        $days = max((int) $this->option('days'), 1);
        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        $start = HistoryWindow::start();
        if ($this->option('from')) {
            try {
                $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('from'), 'Africa/Cairo');
            } catch (Throwable) {
                $from = false;
            }
            if (! $from) {
                $this->error('--from must be a date like 2026-09-01');

                return self::FAILURE;
            }
            if ($from->lessThan($start)) {
                $this->warn('--from '.$from->toDateString().' is before the history start; clamped to '.$start->toDateString());
                $from = $start;
            }
            $days = $from->greaterThan($today) ? 1 : (int) $from->diffInDays($today) + 1;
        } elseif ($days > HistoryWindow::daysFromStart($today)) {
            $this->warn("--days {$days} reaches before the history start; clamped to ".$start->toDateString());
        }
        $accounts = AdAccount::query()->where('is_active', true)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('external'), fn ($q, $ids) => $q->whereIn('external_id', array_map('trim', explode(',', $ids))))->get();
        $failed = 0;

        if ($this->option('queue')) {
            foreach ($accounts as $a) {
                SyncAdAccount::dispatch($a->id, $days, 'backfill', 'backfill');
                $this->line("Queued {$a->name} ({$days} days)");
            }
            $this->info('The worker keeps retrying while Meta asks to wait; each account shows its sync time on Ads -> Ad accounts when it lands.');

            return self::SUCCESS;
        }

        // Runs inline so the chunks stay in order and the command shows progress.
        foreach ($accounts as $a) {
            try {
                // backfill() holds the account's DB claim (AccountSyncClaim), so it never overlaps any other sync of it.
                $run = $sync->backfill($a, $days, 'backfill');
                if (AdsSyncService::isBusy($run)) {
                    $this->warn("Skipped {$a->name}: busy (another sync of this account is running)");
                    $failed++;
                } elseif ($run?->status === 'error') {
                    $this->warn("Failed {$a->name}: ".AdsSyncService::scrub((string) $run->error));
                    $failed++;
                } else {
                    $this->line("Backfilled {$a->name} ({$days} days)");
                }
            } catch (Throwable $e) { // AdsApiException incl. RateLimited, or anything unexpected
                $this->warn("Failed {$a->name}: ".AdsSyncService::scrub($e->getMessage()));
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
