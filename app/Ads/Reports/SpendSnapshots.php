<?php

namespace App\Ads\Reports;

use App\Ads\Platforms\Data\AccountDailyTotal;
use App\Models\AdAccount;
use App\Models\AdSpendSnapshot;
use Carbon\CarbonImmutable;

/**
 * Writes today's control spend for the current Cairo hour; a later sync in the same hour overwrites it. Only the last
 * KEEP_DAYS days are kept (the baseline reads 14): every record() drops older rows, because ads:prune-history is a manual
 * command (not scheduled), so the per-sync prune is the only one that runs on its own.
 *
 * One day for everything (final review B-m10): the snapshot's date and hour are Cairo's, and the platform reports the
 * account's control total on the account's own day. They only agree for a Cairo-day account, so an account on another
 * timezone is skipped explicitly (its "today" is a different window; mixing them would put a wrong curve in the baseline).
 * An account with no timezone recorded is taken as Cairo (every account the business runs today).
 */
final class SpendSnapshots
{
    public const KEEP_DAYS = 30;

    /** @param  list<AccountDailyTotal>  $control */
    public function record(AdAccount $a, array $control): void
    {
        if (! self::onCairoDay($a)) {
            return;
        }
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

    /** True when the account reports on Cairo's day (same UTC offset now), or has no timezone recorded. */
    public static function onCairoDay(AdAccount $a): bool
    {
        $tz = trim((string) $a->timezone);
        if ($tz === '' || $tz === AdsFilter::TIMEZONE) {
            return true;
        }
        try {
            return CarbonImmutable::now($tz)->getOffset() === CarbonImmutable::now(AdsFilter::TIMEZONE)->getOffset();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Deletes snapshots older than KEEP_DAYS Cairo days; returns the rows deleted. */
    public function prune(): int
    {
        $cutoff = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay()->subDays(self::KEEP_DAYS)->toDateString();

        return AdSpendSnapshot::query()->where('date', '<', $cutoff)->delete();
    }
}
