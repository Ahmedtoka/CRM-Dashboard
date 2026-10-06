<?php

namespace App\Ads\Alerts\Rules;

use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Rule;
use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Severity;
use App\Ads\Alerts\Stats;
use Carbon\CarbonImmutable;

/**
 * R #2 all.spend_spike_today (hourly, R-02): today-so-far account spend ≥ 2 × what the account usually has spent by this
 * hour and ≥ the owner minimum. No hourly history is stored, so "by this hour" = 14-day median daily spend × share of the
 * Cairo day elapsed, never below a quarter day (A3). Action "look" (Ads Manager / campaigns), never Stop.
 */
final class SpendSpikeToday implements Rule
{
    public const ID = 'all.spend_spike_today';

    public const RATIO = 2.0;

    public const MIN_DAY_FRACTION = 0.25;

    public const BASE_DAYS = 14;

    public function id(): string
    {
        return self::ID;
    }

    public function schedule(): string
    {
        return self::HOURLY;
    }

    public function isPerformance(): bool
    {
        return false;
    }

    public function cooldownUntil(CarbonImmutable $now): CarbonImmutable
    {
        return $now->endOfDay();
    }

    public function evaluate(RuleContext $ctx): array
    {
        $today = $ctx->day(0);
        $totals = $ctx->data->dailyTotals($ctx->account->id, $ctx->day(-self::BASE_DAYS), $today);
        $todaySpend = (float) ($totals[$today] ?? 0);
        $history = [];
        for ($i = 1; $i <= self::BASE_DAYS; $i++) {
            $history[] = (float) ($totals[$ctx->day(-$i)] ?? 0);
        }
        $median = (float) (Stats::median($history) ?? 0);
        if ($median <= 0 || $todaySpend <= 0) {
            return [];
        }

        $fraction = max(self::MIN_DAY_FRACTION, ($ctx->now->hour * 60 + $ctx->now->minute) / 1440);
        $expected = $median * $fraction;
        $ratio = $todaySpend / $expected;
        $minimum = $ctx->settings->spikeMinAmount();
        if ($ratio < self::RATIO || $todaySpend < $minimum) {
            return [];
        }

        $a = $ctx->account;

        return [new Finding(self::ID, Severity::CRITICAL, 'look', 'account', (int) $a->id, (int) $a->id, null, null, null,
            max(0.0, round($todaySpend / $fraction - $median, 2)), 'spend_spike_today',
            ['account' => (string) $a->name, 'spend' => round($todaySpend), 'ratio' => round($ratio, 1), 'usual' => round($expected)],
            ['today' => $todaySpend, 'median_daily' => $median, 'day_fraction' => round($fraction, 3), 'expected_by_now' => round($expected, 2), 'min_amount' => $minimum])];
    }
}
