<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use Illuminate\Console\Command;

class BackfillAdsCommand extends Command
{
    protected $signature = 'ads:backfill {--account= : Ad account id} {--days=90}';

    protected $description = 'Backfill ad metrics in 30-day chunks, newest first';

    public function handle(): int
    {
        $days = max((int) $this->option('days'), 1);
        $accounts = AdAccount::query()->where('is_active', true)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))->get();

        // Runs inline so the chunks stay in order and the command shows progress.
        foreach ($accounts as $a) {
            SyncAdAccount::dispatchSync($a->id, $days, 'backfill');
            $this->line("Backfilled {$a->name} ({$days} days)");
        }

        return self::SUCCESS;
    }
}
