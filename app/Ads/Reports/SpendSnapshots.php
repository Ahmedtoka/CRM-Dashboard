<?php

namespace App\Ads\Reports;

use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Models\AdAccount;
use App\Models\AdSpendSnapshot;
use Carbon\CarbonImmutable;

/**
 * Writes today's control spend for the current Cairo hour; a later sync in the same hour overwrites it. Only the last
 * KEEP_DAYS days are kept (the baseline reads 14): every record() drops older rows, and ads:prune-history does too.
 */
final class SpendSnapshots
{
    public const KEEP_DAYS = 30;

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
        $this->prune();
    }

    /** Deletes snapshots older than KEEP_DAYS Cairo days; returns the rows deleted. */
    public function prune(): int
    {
        $cutoff = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay()->subDays(self::KEEP_DAYS)->toDateString();

        return AdSpendSnapshot::query()->where('date', '<', $cutoff)->delete();
    }
}
