<?php

namespace App\Ads\Alerts\Jobs;

use App\Ads\Alerts\AlertEngine;
use App\Ads\Alerts\AlertNotifier;
use App\Ads\Alerts\Rule;
use App\Ads\Sync\SyncAdAccount;
use App\Models\AdAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** One account's rule evaluation (spec 7.6, R-24): on the ads-sync lane, never `default`; DB reads only; runtime logged. */
final class EvaluateAccountAlerts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public int $accountId, public string $schedule = Rule::DAILY)
    {
        $this->onQueue(SyncAdAccount::queueName());
        if (config('queue.default') === 'redis') {
            $this->onConnection('redislong');
        }
    }

    public function uniqueId(): string
    {
        return "ads-alerts-{$this->accountId}-{$this->schedule}";
    }

    public function handle(AlertEngine $engine, AlertNotifier $notifier): void
    {
        $account = AdAccount::query()->find($this->accountId);
        if ($account === null || ! $account->is_active) {
            return;
        }
        $started = microtime(true);
        $result = $engine->evaluateAccount($account, $this->schedule);
        Log::info('ads.alerts.evaluated', [
            'account_id' => $account->id, 'schedule' => $this->schedule, 'opened' => count($result['opened']),
            'refreshed' => $result['refreshed'], 'resolved' => $result['resolved'], 'skipped' => $result['skipped'],
            'fresh' => $result['fresh'], 'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
        $notifier->critical($result['opened']);
    }
}
