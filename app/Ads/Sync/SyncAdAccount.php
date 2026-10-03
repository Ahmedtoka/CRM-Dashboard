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

    /**
     * Meta's usage quota is shared (Arena syncs the same accounts on the same app), so a backfill can meet
     * «retry later» for hours. Each RateLimited releases the job for 15 minutes and uses one try: 30 tries
     * keep it coming back for about 7.5 hours, while a real error still fails it after 3 (maxExceptions).
     */
    public int $tries = 30;

    public int $maxExceptions = 3;

    public int $backoff = 60;

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

    /**
     * The nightly 30-day run has its own lock: at 03:15 the 03:10 hourly job of the same account is
     * often still waiting on the single commercelong worker, and sharing its lock would drop the deep
     * run silently. One worker runs them one after the other.
     */
    public function uniqueId(): string
    {
        return self::uniqueIdFor($this->accountId).($this->kind === 'recent' && $this->days > 3 ? '-deep' : '');
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
