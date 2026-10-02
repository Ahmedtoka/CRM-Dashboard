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
use Illuminate\Queue\Jobs\SyncJob;

/** Syncs one ad account; kind 'backfill' walks $days in 30-day chunks. */
class SyncAdAccount implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** A 90-day backfill pages ads, insights and media: far beyond the 60-s default worker. */
    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    /** A crashed worker must not hold the ads-sync lock forever (just above $timeout). */
    public int $uniqueFor = 3700;

    public function __construct(public int $accountId, public int $days = 3, public string $kind = 'recent')
    {
        // Same long lane as ReconcileShopify: `commercelong` queue (Supervisor program
        // crm-commercelong, --timeout=3600); on Redis it runs on `redislong` (retry_after 3700 s).
        $this->onQueue('commercelong');

        if (config('queue.default') === 'redis') {
            $this->onConnection('redislong');
        }
    }

    public static function uniqueIdFor(int $accountId): string
    {
        return 'ads-sync-'.$accountId;
    }

    /** Cache key of the ShouldBeUnique lock (Laravel: laravel_unique_job:{class}{uniqueId}). */
    public static function lockKey(int $accountId): string
    {
        return 'laravel_unique_job:'.self::class.self::uniqueIdFor($accountId);
    }

    public function uniqueId(): string
    {
        return self::uniqueIdFor($this->accountId);
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
            // Only a real async queue job can be released; sync/inline runs must surface the failure.
            if ($this->job && ! $this->job instanceof SyncJob) {
                $this->release(900);

                return;
            }
            throw $e;
        }
    }
}
