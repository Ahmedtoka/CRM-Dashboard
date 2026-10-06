<?php

namespace App\Ads\Reports;

use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Models\AdAccount;
use App\Models\AdSpendSnapshot;
use Carbon\CarbonImmutable;

/** Writes today's control spend for the current Cairo hour; a later sync in the same hour overwrites it. */
final class SpendSnapshots
{
    /** @param  list<AccountDailyTotal>  $control */
    public function record(AdAccount $a, array $control): void
    {
        $now = CarbonImmutable::now(AdsFilter::TIMEZONE);
        $today = $now->toDateString();
        foreach ($control as $t) {
            if (substr($t->date, 0, 10) !== $today) {
                continue;
            }
            AdSpendSnapshot::query()->upsert(
                [['ad_account_id' => $a->id, 'date' => $today, 'hour' => (int) $now->format('G'), 'spend' => round($t->spend, 2), 'captured_at' => now()->toDateTimeString()]],
                ['ad_account_id', 'date', 'hour'], ['spend', 'captured_at'],
            );
        }
    }
}
