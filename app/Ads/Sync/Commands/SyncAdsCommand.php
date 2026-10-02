<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Platforms\RateLimited;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use Illuminate\Console\Command;

class SyncAdsCommand extends Command
{
    protected $signature = 'ads:sync {--account= : Ad account id} {--platform= : meta|tiktok|google} {--days=3} {--now : Run synchronously, no queue}';

    protected $description = 'Sync ads and daily metrics for the active ad accounts';

    public function handle(): int
    {
        $accounts = AdAccount::query()->where('is_active', true)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('platform'), fn ($q, $p) => $q->where('platform', $p))
            ->get();
        $days = max((int) $this->option('days'), 1);

        foreach ($accounts as $a) {
            if ($this->option('now')) {
                try {
                    SyncAdAccount::dispatchSync($a->id, $days);
                    $this->line("Synced {$a->name}");
                } catch (RateLimited $e) {
                    $this->warn("Rate limited: {$a->name}");
                }
            } else {
                SyncAdAccount::dispatch($a->id, $days);
            }
        }
        $this->info(($this->option('now') ? 'Synced ' : 'Queued ').$accounts->count().' account(s).');

        return self::SUCCESS;
    }
}
