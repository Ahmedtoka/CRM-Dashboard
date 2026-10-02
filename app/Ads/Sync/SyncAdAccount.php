<?php

namespace App\Ads\Sync;

use App\Ads\Platforms\RateLimited;
use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Syncs one ad account; kind 'backfill' walks $days in 30-day chunks. */
class SyncAdAccount implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $accountId, public int $days = 3, public string $kind = 'recent') {}

    public function uniqueId(): string
    {
        return 'ads-sync-'.$this->accountId;
    }

    public function handle(AdsSyncService $sync): void
    {
        $account = AdAccount::find($this->accountId);
        if (! $account || ! $account->is_active) {
            return;
        }

        try {
            if ($this->kind === 'backfill') {
                $sync->backfill($account, $this->days);

                return;
            }
            $to = CarbonImmutable::now('Africa/Cairo')->startOfDay();
            $sync->syncAccount($account, $to->subDays(max($this->days, 1) - 1), $to, $this->kind);
        } catch (RateLimited $e) {
            if ($this->job) {
                $this->release(900);

                return;
            }
            throw $e;
        }
    }
}
