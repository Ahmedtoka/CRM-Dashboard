<?php

namespace App\Ads\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Trend and creative fatigue per ad, anchored at a day (`to`):
 *
 *   trend     ROAS and spend of the last 7 days (to-6..to) vs the 7 before (to-13..to-7); dir up/down when ROAS moved
 *             by 10 % or more, else flat; the percentages are null when the prior window has no spend
 *   fatigue   CTR of the last 3 days vs the ad's first 7 active days (spend > 0), and frequency (impressions ÷ reach)
 *             of the last 7 days; flagged when CTR fell 30 % or more and frequency is 2.5 or more
 */
final class AdInsights
{
    public const TREND_DAYS = 7;

    public const TREND_DIR_PCT = 10.0;

    public const FATIGUE_RECENT_DAYS = 3;

    public const FATIGUE_BASE_DAYS = 7;

    public const FATIGUE_CTR_DROP_PCT = 30.0;

    public const FATIGUE_FREQUENCY = 2.5;

    /**
     * @param  list<int>  $adIds
     * @return array<int, array{trend: array{roas_pct: ?float, spend_pct: ?float, dir: 'up'|'down'|'flat'}, fatigue: array{flag: bool, ctr_drop_pct: ?float, frequency: ?float}}>
     */
    public function forAds(array $adIds, CarbonImmutable $to): array
    {
        $adIds = array_values(array_unique(array_map('intval', $adIds)));
        if ($adIds === []) {
            return [];
        }

        $day = $to->toDateString();
        $last7 = $to->subDays(self::TREND_DAYS - 1)->toDateString();
        $prior7 = $to->subDays(2 * self::TREND_DAYS - 1)->toDateString();
        $last3 = $to->subDays(self::FATIGUE_RECENT_DAYS - 1)->toDateString();

        $rows = DB::table('ad_daily_metrics as m')
            ->whereIn('m.ad_id', $adIds)
            ->where('m.date', '<=', $day)
            ->where('m.date', '>=', $prior7)
            ->groupBy('m.ad_id')
            ->selectRaw('m.ad_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN m.date >= ? THEN m.spend ELSE 0 END), 0) as spend_a', [$last7])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.date >= ? THEN m.purchase_value ELSE 0 END), 0) as value_a', [$last7])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.date < ? THEN m.spend ELSE 0 END), 0) as spend_b', [$last7])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.date < ? THEN m.purchase_value ELSE 0 END), 0) as value_b', [$last7])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.date >= ? THEN m.impressions ELSE 0 END), 0) as impr_a', [$last7])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.date >= ? THEN m.reach ELSE 0 END), 0) as reach_a', [$last7])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.date >= ? THEN m.impressions ELSE 0 END), 0) as impr_3', [$last3])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.date >= ? THEN m.clicks ELSE 0 END), 0) as clicks_3', [$last3])
            ->get()->keyBy(fn ($r) => (int) $r->ad_id);

        $base = $this->baselineCtr($adIds, $last3, $day);

        $out = [];
        foreach ($adIds as $id) {
            $r = $rows[$id] ?? null;
            $out[$id] = [
                'trend' => $this->trend($r),
                'fatigue' => $this->fatigue($r, $base[$id] ?? null),
            ];
        }

        return $out;
    }

    /** @return array{roas_pct: ?float, spend_pct: ?float, dir: 'up'|'down'|'flat'} */
    private function trend(?object $r): array
    {
        $flat = ['roas_pct' => null, 'spend_pct' => null, 'dir' => 'flat'];
        if ($r === null || (float) $r->spend_b <= 0) {
            return $flat;
        }

        $spendA = (float) $r->spend_a;
        $spendB = (float) $r->spend_b;
        $roasA = $spendA > 0 ? (float) $r->value_a / $spendA : 0.0;
        $roasB = (float) $r->value_b / $spendB;
        $spendPct = round(($spendA - $spendB) / $spendB * 100, 1);

        if ($roasB <= 0) {
            // nothing to compare against: a percentage is meaningless, but a first return is still an improvement
            return ['roas_pct' => null, 'spend_pct' => $spendPct, 'dir' => $roasA > 0 ? 'up' : 'flat'];
        }

        $roasPct = round(($roasA - $roasB) / $roasB * 100, 1);
        $dir = match (true) {
            $roasPct >= self::TREND_DIR_PCT => 'up',
            $roasPct <= -self::TREND_DIR_PCT => 'down',
            default => 'flat',
        };

        return ['roas_pct' => $roasPct, 'spend_pct' => $spendPct, 'dir' => $dir];
    }

    /** @return array{flag: bool, ctr_drop_pct: ?float, frequency: ?float} */
    private function fatigue(?object $r, ?float $baseCtr): array
    {
        $frequency = $r !== null && (int) $r->reach_a > 0 ? round((float) $r->impr_a / (float) $r->reach_a, 2) : null;
        $recentCtr = $r !== null && (int) $r->impr_3 > 0 ? (float) $r->clicks_3 / (float) $r->impr_3 : null;
        $drop = $baseCtr !== null && $baseCtr > 0 && $recentCtr !== null ? round(($baseCtr - $recentCtr) / $baseCtr * 100, 1) : null;

        return [
            'flag' => $drop !== null && $frequency !== null && $drop >= self::FATIGUE_CTR_DROP_PCT && $frequency >= self::FATIGUE_FREQUENCY,
            'ctr_drop_pct' => $drop,
            'frequency' => $frequency,
        ];
    }

    /**
     * CTR over each ad's first 7 active days (spend > 0, all history up to `to`). Null when those days are not
     * all before the recent window (an ad younger than 10 active days has no separate baseline).
     *
     * @param  list<int>  $adIds
     * @return array<int, ?float>
     */
    private function baselineCtr(array $adIds, string $recentFrom, string $to): array
    {
        $byAd = DB::table('ad_daily_metrics')
            ->whereIn('ad_id', $adIds)
            ->where('spend', '>', 0)
            ->where('date', '<=', $to)
            ->orderBy('ad_id')->orderBy('date')
            ->get(['ad_id', 'date', 'impressions', 'clicks'])
            ->groupBy('ad_id');

        $out = [];
        foreach ($byAd as $id => $days) {
            $first = $days->take(self::FATIGUE_BASE_DAYS);
            if ($first->count() < self::FATIGUE_BASE_DAYS || substr((string) $first->last()->date, 0, 10) >= $recentFrom) {
                $out[(int) $id] = null;

                continue;
            }
            $impr = (int) $first->sum('impressions');
            $out[(int) $id] = $impr > 0 ? (float) $first->sum('clicks') / $impr : null;
        }

        return $out;
    }
}
