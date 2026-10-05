<?php

namespace App\Ads\Sync;

use App\Ads\Platforms\RateLimited;
use App\Models\AdAccount;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Syncs one ad account; kind 'backfill' walks $days in 30-day chunks. */
class SyncAdAccount implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Meta's usage quota is shared (Arena syncs the same accounts on the same app), so a backfill can meet
     * «retry later» for hours. Each RateLimited releases the job for Meta's regain time (quota admission: at least
     * 5 minutes; 15 minutes when Meta gave none) and uses one try, while a real error still fails it after 3 (maxExceptions).
     */
    public int $tries = 30;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    /** A 90-day backfill pages ads, insights and media: far beyond the 60-s default worker. */
    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    /** A crashed worker must not hold the ads-sync lock forever (just above $timeout). */
    public int $uniqueFor = 3700;

    /** Same in every copy of this job: Redis re-queues a job that runs past the connection's retry_after. Null on jobs queued before it existed. */
    public ?string $runKey = null;

    /** Why this run exists: schedule | manual | backfill | setup. Declared with defaults so payloads queued before it existed still unserialize. */
    public string $trigger = 'schedule';

    /** The user who clicked, for a manual run. */
    public ?int $triggeredById = null;

    /** false = the hourly run: metrics, control totals and campaign statuses, no ad list (that is read nightly). */
    public bool $withAds = true;

    public function __construct(public int $accountId, public int $days = 3, public string $kind = 'recent', string $trigger = 'schedule', ?int $triggeredById = null, bool $withAds = true)
    {
        $this->trigger = $trigger;
        $this->triggeredById = $triggeredById;
        $this->withAds = $withAds;
        $this->runKey = (string) Str::uuid();

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

    /**
     * Runs once per dispatch. The Cloudways commercelong worker reads the `redis` connection (retry_after
     * 90 s), so a sync that takes minutes is put back in the queue while it runs and comes round again
     * once it ends: that copy finds the done mark (or the running lock) and leaves without calling Meta.
     */
    public function handle(AdsSyncService $sync): void
    {
        if ($this->runKey === null) {
            if ($this->run($sync)) {
                $this->clearPending();
            }

            return;
        }

        $done = 'ads-sync-done:'.$this->runKey;
        if (Cache::has($done)) {
            return;
        }
        $running = Cache::lock('ads-sync-running:'.$this->runKey, $this->timeout + 60);
        if (! $running->get()) {
            return;
        }

        try {
            if ($this->run($sync)) {
                Cache::put($done, true, now()->addHours(12));
                $this->clearPending();
            }
        } finally {
            $running->release();
        }
    }

    /**
     * The hourly dispatch marks the account pending (ads:sync, CAS on ad_accounts.sync_pending_since). Any sync job of
     * the account that ends clears it: the hourly job itself, or the manual/backfill job holding the same unique lock
     * that made the hourly dispatch a no-op. A released job keeps it, so no second hourly job piles up meanwhile.
     */
    private function clearPending(): void
    {
        AdAccount::whereKey($this->accountId)->whereNotNull('sync_pending_since')->update(['sync_pending_since' => null]);
    }

    /** The queue gave up (timeout, exhausted tries, a crash): close this job's run, and only this one. */
    public function failed(Throwable $e): void
    {
        $message = AdsSyncService::scrub($e->getMessage());
        if ($this->runKey !== null) {
            AdsSyncRun::where('run_key', $this->runKey)->where('status', 'running')
                ->update(['status' => 'error', 'error' => $message, 'finished_at' => now()]);
        }
        $this->clearPending();
        Log::error('ads sync job failed', ['account' => $this->accountId, 'error' => $message]);
    }

    /** @return bool false when released for a later try (Meta asked to wait) */
    private function run(AdsSyncService $sync): bool
    {
        $account = AdAccount::find($this->accountId);
        if (! $account || ! $account->is_active) {
            return true;
        }

        try {
            if ($this->kind === 'backfill') {
                // The run key doubles as the batch key: a released backfill resumes after its last 'ok' chunk.
                $run = $sync->backfill($account, $this->days, $this->trigger, $this->triggeredById, $this->runKey, $this->runKey);

                return ! $this->releasedWhileBusy($run);
            }
            $to = CarbonImmutable::now('Africa/Cairo')->startOfDay();
            $window = HistoryWindow::clamp($to->subDays(max($this->days, 1) - 1), $to);
            if ($window === null) {
                // The whole window is before crm.ads.history_start: leave a visible 'skipped' run, call nothing.
                AdsSyncRun::create([
                    'ad_account_id' => $account->id, 'platform' => $account->platform, 'kind' => $this->kind, 'status' => 'skipped',
                    'trigger' => $this->trigger, 'triggered_by_id' => $this->triggeredById, 'error' => 'Window before history start',
                    'from_date' => $to->subDays(max($this->days, 1) - 1)->toDateString(), 'to_date' => $to->toDateString(),
                    'started_at' => now(), 'finished_at' => now(),
                ]);

                return true;
            }
            $run = $sync->syncAccount($account, $window[0], $window[1], $this->kind, $this->withAds, $this->trigger, $this->triggeredById, $this->runKey);

            return ! $this->releasedWhileBusy($run);
        } catch (RateLimited $e) {
            // Only a real async queue job can be released; sync/inline runs must surface the failure.
            // Meta's regain time (or the admission delay) when known, else 15 minutes.
            if ($this->job && ! $this->job instanceof SyncJob) {
                $this->release($e->retryAfterSeconds ?? 900);

                return false;
            }
            throw $e;
        }
    }

    /** Another sync holds the account claim: a queued job comes back in 2 minutes; an inline run just ends. */
    private function releasedWhileBusy(?AdsSyncRun $run): bool
    {
        if (AdsSyncService::isBusy($run) && $this->job && ! $this->job instanceof SyncJob) {
            $this->release(120);

            return true;
        }

        return false;
    }
}
