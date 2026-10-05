<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Attribution\OrderAttribution;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\ConnectionHealth;
use App\Ads\Sync\HistoryWindow;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncAdsCommand extends Command
{
    protected $signature = 'ads:sync {--account= : Ad account id} {--platform= : meta|tiktok|google} {--days=3} {--now : Run synchronously, no queue}';

    protected $description = 'Sync ads and daily metrics for the active ad accounts';

    /** A pending hourly sync older than this is taken over (its job was lost). */
    public const PENDING_HOURS = 8;

    public function handle(AdsSyncService $sync, OrderAttribution $attribution): int
    {
        $days = max((int) $this->option('days'), 1);
        $failed = 0;

        if ($days >= 30) {
            $this->discoverAccounts($sync);
        }

        $accounts = AdAccount::query()->where('is_active', true)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('platform'), fn ($q, $p) => $q->where('platform', $p))
            ->get();

        foreach ($accounts as $a) {
            // A dead token is not retried every hour: one probe per connection per hour (the first account is it).
            if ($a->connection !== null && ! ConnectionHealth::claimProbe($a->connection)) {
                $this->line("Skipped {$a->name}: the connection needs a new token");

                continue;
            }
            if (! $this->option('now')) {
                if ($days >= AdsSyncService::DEEP_DAYS) {
                    // Nightly deep sync: the full ad list, the status sweep; its own unique lock.
                    SyncAdAccount::dispatch($a->id, $days, 'recent', 'schedule');

                    continue;
                }
                // Hourly: no ad list (nightly only), and at most one pending job per account (A5).
                if (! $this->claimPending($a)) {
                    $this->line("Skipped {$a->name}: a sync is already pending");

                    continue;
                }
                SyncAdAccount::dispatch($a->id, $days, 'recent', 'schedule', null, false);

                continue;
            }
            try {
                $to = CarbonImmutable::now('Africa/Cairo')->startOfDay();
                $window = HistoryWindow::clamp($to->subDays($days - 1), $to);
                if ($window === null) {
                    $this->warn("Skipped {$a->name}: window is before the history start");

                    continue;
                }
                $run = $sync->syncAccount($a, $window[0], $window[1], 'recent', true, 'manual');
                if ($run->status === 'error') {
                    $this->warn("Failed {$a->name}: ".AdsSyncService::scrub((string) $run->error));
                    $failed++;
                } else {
                    $this->line("Synced {$a->name}");
                }
            } catch (Throwable $e) { // AdsApiException incl. RateLimited, or anything unexpected
                $this->warn("Failed {$a->name}: ".AdsSyncService::scrub($e->getMessage()));
                $failed++;
            }
        }
        if ($days >= 30) { // nightly deep sync: re-resolve recent orders against the ads already stored (a queued sync may not have finished yet; the hourly run catches up)
            $this->attributeOrders($attribution);
        }

        $this->info(($this->option('now') ? 'Synced ' : 'Queued ').($accounts->count() - ($this->option('now') ? $failed : 0)).' account(s).');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * CAS on ad_accounts.sync_pending_since: true for the one caller that marks the account pending. A marker older
     * than PENDING_HOURS is taken over (its job was lost); ads:sweep-stuck-runs also clears those.
     */
    private function claimPending(AdAccount $a): bool
    {
        $now = now();

        return DB::table('ad_accounts')->where('id', $a->id)
            ->where(fn ($q) => $q->whereNull('sync_pending_since')->orWhere('sync_pending_since', '<', $now->copy()->subHours(self::PENDING_HOURS)))
            ->update(['sync_pending_since' => $now]) === 1;
    }

    /** Best effort: an attribution failure must not fail the sync. */
    private function attributeOrders(OrderAttribution $attribution): void
    {
        try {
            $to = CarbonImmutable::now();
            $this->line('Attributed '.$attribution->run($to->subDays(35)->startOfDay(), $to).' order(s).');
        } catch (Throwable $e) {
            $this->warn('Order attribution failed: '.AdsSyncService::scrub($e->getMessage()));
        }
    }

    /** Nightly deep sync: pick up newly granted ad accounts. A failure is warned (and recorded on the connection), not fatal. */
    private function discoverAccounts(AdsSyncService $sync): void
    {
        $connections = AdPlatformConnection::query()->whereNotIn('status', ['disabled', 'needs_reconnect'])
            ->when($this->option('platform'), fn ($q, $p) => $q->where('platform', $p))
            ->when($this->option('account'), fn ($q, $id) => $q->whereIn('id', AdAccount::whereKey($id)->select('connection_id')))
            ->get();
        foreach ($connections as $c) {
            try {
                $sync->syncAccounts($c);
            } catch (AdsApiException $e) {
                $this->warn("Account discovery failed for {$c->name}: ".AdsSyncService::scrub($e->getMessage()));
            }
        }
    }
}
