<?php

namespace App\Ads\Alerts\Commands;

use App\Ads\Alerts\AlertStore;
use App\Ads\Alerts\Jobs\EvaluateAccountAlerts;
use App\Ads\Alerts\Rule;
use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class EvaluateAlertsCommand extends Command
{
    protected $signature = 'ads:alerts {--scope=daily : hourly|daily} {--account=* : only these ad account ids} {--sync : evaluate inline}';

    protected $description = 'Evaluate the ads decision rules, one job per active ad account on the ads sync queue.';

    public function handle(AlertStore $store): int
    {
        $scope = (string) $this->option('scope');
        if (! in_array($scope, [Rule::HOURLY, Rule::DAILY], true)) {
            $this->error('--scope must be hourly or daily');

            return self::INVALID;
        }

        $woke = $store->wakeSnoozed(CarbonImmutable::now());
        $only = array_map('intval', (array) $this->option('account'));
        $ids = AdAccount::query()->where('is_active', true)->when($only !== [], fn ($q) => $q->whereIn('id', $only))->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $job = new EvaluateAccountAlerts((int) $id, $scope);
            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
        }
        $this->info("{$ids->count()} account(s) queued for {$scope} rules, {$woke} snoozed alert(s) back to open.");

        return self::SUCCESS;
    }
}
