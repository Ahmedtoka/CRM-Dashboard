<?php

namespace App\Ads\Reports;

use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «فلوس النهارده» (U 2.3): today's spend so far against the usual spend by this hour — the median, over the last 14
 * days, of each day's cumulative spend at the same Cairo hour (same baseline as S5 spend_spike_today, so the number and
 * the alert agree). Until 3 days of snapshots exist the baseline is the last 7 complete days pro-rated by time of day.
 */
final class SpendByHour
{
    public const BASELINE_DAYS = 14;

    public const MIN_SNAPSHOT_DAYS = 3;

    public function __construct(private readonly AdsQuery $q) {}

    public function today(AdsFilter $f): array
    {
        $now = CarbonImmutable::now(AdsFilter::TIMEZONE);
        $today = $now->startOfDay();
        $hour = (int) $now->format('G');
        $todayF = $f->allSpend()->with(['from' => $today, 'to' => $today]);
        $soFar = round((float) ($this->q->accountTotals($todayF)?->spend ?? ($this->q->sums($todayF)->first()->spend ?? 0)), 2);

        $accounts = $this->accounts($f, $today);
        $rows = $accounts === [] ? collect() : DB::table('ad_spend_snapshots')->whereIn('ad_account_id', $accounts)
            ->whereBetween('date', [$today->subDays(self::BASELINE_DAYS)->toDateString(), $today->toDateString()])
            ->get(['ad_account_id', 'date', 'hour', 'spend']);
        // date => account => hour => spend
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[substr((string) $r->date, 0, 10)][(int) $r->ad_account_id][(int) $r->hour] = (float) $r->spend;
        }
        $at = function (array $perAccount, int $h): float {
            $sum = 0.0;
            foreach ($perAccount as $hours) {
                $best = null;
                foreach ($hours as $hh => $spend) {
                    if ($hh <= $h && ($best === null || $hh > $best)) {
                        $best = $hh;
                    }
                }
                $sum += $best === null ? 0.0 : $hours[$best];
            }

            return $sum;
        };
        $past = array_filter($byDay, fn ($v, $d) => $d !== $today->toDateString(), ARRAY_FILTER_USE_BOTH);

        $hours = [];
        for ($h = 0; $h <= $hour; $h++) {
            $hours[] = [
                'hour' => $h,
                'today' => round($at($byDay[$today->toDateString()] ?? [], $h), 2),
                'usual' => count($past) >= self::MIN_SNAPSHOT_DAYS ? self::median(array_map(fn ($d) => $at($d, $h), $past)) : null,
            ];
        }

        if (count($past) >= self::MIN_SNAPSHOT_DAYS) {
            $usual = self::median(array_map(fn ($d) => $at($d, $hour), $past));
            $baseline = 'snapshots';
        } else {
            [$from, $to] = AdsFilter::preset('last7', $today);
            $week = (float) ($this->q->sums($f->allSpend()->with(['from' => $from, 'to' => $to]))->first()->spend ?? 0);
            $minutes = $now->diffInMinutes($today, true);
            $usual = $week > 0 ? round($week / 7 * $minutes / 1440, 2) : null;
            $baseline = $usual === null ? 'none' : 'prorated';
        }

        return [
            'spend_so_far' => $soFar,
            'usual_by_now' => $usual,
            'ratio' => $usual !== null && $usual > 0 ? round($soFar / $usual, 2) : null,
            'baseline' => $baseline,
            'hour' => $hour,
            'hours' => $hours,
        ];
    }

    /** @return list<int> accounts in scope today (filter accounts, platform, picked buyer) */
    private function accounts(AdsFilter $f, CarbonImmutable $today): array
    {
        if ($f->isEmpty()) {
            return [];
        }
        $q = AdAccount::query()->where('is_active', true)
            ->when($f->accountIds !== null, fn ($q) => $q->whereIn('id', $f->accountIds))
            ->when($f->platform !== null, fn ($q) => $q->where('platform', $f->platform));
        $buyer = $f->restrictBuyerId ?? $f->buyerId;
        if ($buyer !== null) {
            $held = AdAccountAssignment::query()->where('media_buyer_id', $buyer)->where('starts_on', '<=', $today->toDateString())
                ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today->toDateString()))->pluck('ad_account_id');
            $q->whereIn('id', $held);
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 2);
    }
}
