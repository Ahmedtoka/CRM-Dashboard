<?php

namespace App\Ads\Alerts\Commands;

use App\Ads\Alerts\AlertNotifier;
use App\Ads\Alerts\AlertStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class AlertsDigestCommand extends Command
{
    protected $signature = 'ads:alerts-digest';

    protected $description = 'Send the 09:00 grouped bell item of open ads decisions (only when notifications are on).';

    public function handle(AlertNotifier $notifier, AlertStore $store): int
    {
        $store->wakeSnoozed(CarbonImmutable::now()); // a card due back at 09:00 belongs in the 09:00 digest
        $this->info($notifier->digest(CarbonImmutable::now()).' digest notification(s) sent.');

        return self::SUCCESS;
    }
}
