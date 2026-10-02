<?php

namespace App\Ads\Reports;

use App\Ads\AdsSettings;
use Illuminate\Support\Facades\DB;

/**
 * Winner creatives — a port of ArenaReports' WinnerAdService (Meta revenue mode), on local tables:
 *
 *   window      the filter range clamped to 7..30 days, anchored at its end
 *   avgROAS     the ad account's ROAS over the window (all of the account's rows)
 *   smoothed    (revenue + avgROAS × K × 500) ÷ (spend + K × 500), K = 1
 *   recent      the same over the last 7 days of the window (null when no spend there)
 *   blended     (smoothed + 1.25 × recent) ÷ 2.25, or smoothed when recent is null
 *   consistency days with a sale ÷ active days (spend > 0)
 *   score       round(min(blended ÷ winner, 1) × 70 + consistency × 30), 0..100
 *   gate        spend ≥ min_spend and active days ≥ min_days, else the ad is left out
 *   tier        winner (smoothed ≥ winner) · promising (≥ promising) · loser (< loser and spend ≥ loser_min_spend) · neutral
 *
 * Revenue = platform purchase value; spend is pre-tax. Thresholds come from AdsSettings.
 */
final class WinnerScorer
{
    public const SMOOTHING_K = 1.0;

    public const SPEND_PRIOR = 500.0;

    public const RECENCY_DAYS = 7;

    public const RECENCY_WEIGHT = 1.25;

    public const SCORE_ROAS_PTS = 70;

    public const SCORE_CONSISTENCY_PTS = 30;

    public const MIN_WINDOW_DAYS = 7;

    public const MAX_WINDOW_DAYS = 30;

    public const SORTS = ['score', 'roas', 'spend', 'revenue', 'date'];

    public function __construct(
        private readonly AdsQuery $q,
        private readonly RunningCreatives $creatives,
        private readonly AdsSettings $settings,
    ) {}

    /** @return list<array{ad: array, score:int, tier:string, smoothed_roas:float, blended_roas:float, roas:?float, spend:float, revenue:float, orders:float, cpa:?float, ctr:?float, cvr:?float, active_days:int, days_with_sales:int, recommendation:string}> */
    public function build(AdsFilter $f, string $status = 'all', string $sort = 'score'): array
    {
        $w = $this->window($f);
        $thr = $this->settings->winnerThresholds();
        $recentCut = $w->to->subDays(self::RECENCY_DAYS - 1)->toDateString();
        $prior = self::SMOOTHING_K * self::SPEND_PRIOR;

        $perAd = $this->q->sums($w, ['ad_id' => 'm.ad_id', 'account_id' => 'm.ad_account_id'], function ($b) use ($status, $recentCut) {
            $b->selectRaw('COALESCE(SUM(CASE WHEN m.spend > 0 THEN 1 ELSE 0 END), 0) as active_days')
                ->selectRaw('COALESCE(SUM(CASE WHEN m.spend > 0 AND m.purchases > 0 THEN 1 ELSE 0 END), 0) as days_with_sales')
                ->selectRaw('COALESCE(SUM(CASE WHEN m.date >= ? THEN m.spend ELSE 0 END), 0) as recent_spend', [$recentCut])
                ->selectRaw('COALESCE(SUM(CASE WHEN m.date >= ? THEN m.purchase_value ELSE 0 END), 0) as recent_value', [$recentCut]);
            if ($status === 'active') {
                $b->where('ad.effective_status', 'ACTIVE');
            } elseif ($status === 'inactive') {
                $b->where(fn ($q) => $q->whereNull('ad.effective_status')->orWhere('ad.effective_status', '!=', 'ACTIVE'));
            }
        });

        $eligible = $perAd->filter(fn ($r) => (float) $r->spend >= (float) $thr['min_spend'] && (int) $r->active_days >= (int) $thr['min_days']);
        if ($eligible->isEmpty()) {
            return [];
        }

        $avg = $this->accountAverages($w, $eligible->pluck('account_id')->unique()->values()->all());

        $scored = $eligible->map(function (object $r) use ($avg, $prior, $thr) {
            $spend = (float) $r->spend;
            $revenue = (float) $r->purchase_value;
            $avgRoas = $avg[(int) $r->account_id] ?? 0.0;

            $smoothed = ($revenue + $avgRoas * $prior) / ($spend + $prior);
            $recentSpend = (float) $r->recent_spend;
            $recent = $recentSpend > 0 ? ((float) $r->recent_value + $avgRoas * $prior) / ($recentSpend + $prior) : null;
            $blended = $recent !== null ? ($smoothed + self::RECENCY_WEIGHT * $recent) / (1 + self::RECENCY_WEIGHT) : $smoothed;

            $active = (int) $r->active_days;
            $withSales = (int) $r->days_with_sales;
            $consistency = $active > 0 ? $withSales / $active : 0.0;

            $score = (int) round(min($blended / (float) $thr['winner'], 1.0) * self::SCORE_ROAS_PTS + $consistency * self::SCORE_CONSISTENCY_PTS);
            $tier = match (true) {
                $smoothed >= (float) $thr['winner'] => 'winner',
                $smoothed >= (float) $thr['promising'] => 'promising',
                $smoothed < (float) $thr['loser'] && $spend >= (float) $thr['loser_min_spend'] => 'loser',
                default => 'neutral',
            };
            $d = $this->q->derive($r);

            return [
                'ad_id' => (int) $r->ad_id,
                'score' => max(0, min(100, $score)),
                'tier' => $tier,
                'smoothed_roas' => round($smoothed, 2),
                'blended_roas' => round($blended, 2),
                'roas' => $d['roas'],
                'spend' => $d['spend'],
                'revenue' => $d['purchase_value'],
                'orders' => $d['purchases'],
                'cpa' => $d['cpa'],
                'ctr' => $d['ctr'],
                'cvr' => AdsQuery::ratio((float) $r->purchases, (int) $r->clicks, 4),
                'active_days' => $active,
                'days_with_sales' => $withSales,
                'recommendation' => __('ads.recommendation.'.$tier),
            ];
        })->keyBy('ad_id');

        $ads = collect($this->creatives->rows($this->adRows($perAd->whereIn('ad_id', $scored->keys()->all())->all()), $w))->keyBy('id');
        $out = $scored->map(function (array $s) use ($ads) {
            $id = $s['ad_id'];
            unset($s['ad_id']);

            return ['ad' => $ads[$id]] + $s;
        });

        $sort = in_array($sort, self::SORTS, true) ? $sort : 'score';
        $key = match ($sort) {
            'score' => fn ($x) => [-$x['score'], -$x['spend'], -$x['ad']['id']],
            'roas' => fn ($x) => [-($x['roas'] ?? 0), -$x['score'], -$x['ad']['id']],
            'spend' => fn ($x) => [-$x['spend'], -$x['score'], -$x['ad']['id']],
            'revenue' => fn ($x) => [-$x['revenue'], -$x['score'], -$x['ad']['id']],
            'date' => fn ($x) => [-(int) strtotime((string) ($x['ad']['created_time'] ?? '1970-01-01')), -$x['score'], -$x['ad']['id']],
        };

        return $out->sortBy($key)->values()->all();
    }

    /** The filter with its range clamped to 7..30 days, anchored at `to` (Arena's window policy). */
    public function window(AdsFilter $f): AdsFilter
    {
        $span = (int) $f->from->diffInDays($f->to) + 1;
        $days = max(self::MIN_WINDOW_DAYS, min(self::MAX_WINDOW_DAYS, $span));

        return $f->with(['from' => $f->to->subDays($days - 1)]);
    }

    /**
     * Account-wide ROAS per ad account over the window — every row of the account (no buyer or status
     * filter), so the same ad is smoothed toward the same prior on every screen.
     *
     * @param  list<int>  $accountIds
     * @return array<int, float>
     */
    private function accountAverages(AdsFilter $w, array $accountIds): array
    {
        return DB::table('ad_daily_metrics as m')
            ->whereIn('m.ad_account_id', $accountIds)
            ->whereBetween('m.date', [$w->fromDate(), $w->toDate()])
            ->groupBy('m.ad_account_id')
            ->selectRaw('m.ad_account_id as account_id, COALESCE(SUM(m.spend), 0) as spend, COALESCE(SUM(m.purchase_value), 0) as value')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->account_id => (float) $r->spend > 0 ? (float) $r->value / (float) $r->spend : 0.0])
            ->all();
    }

    /**
     * The scored ads joined like RunningCreatives rows, carrying the window sums.
     *
     * @param  list<object>  $sums
     * @return list<object>
     */
    private function adRows(array $sums): array
    {
        $byId = collect($sums)->keyBy(fn ($r) => (int) $r->ad_id);

        return DB::table('ads as ad')
            ->join('ad_accounts as acc', 'acc.id', '=', 'ad.ad_account_id')
            ->leftJoin('ad_campaigns as camp', 'camp.id', '=', 'ad.ad_campaign_id')
            ->leftJoin('ad_sets as st', 'st.id', '=', 'ad.ad_set_id')
            ->whereIn('ad.id', $byId->keys()->all())
            ->select(RunningCreatives::AD_COLUMNS)->addSelect(['acc.name as account_name', 'acc.platform', 'camp.name as campaign_name', 'st.name as adset_name'])
            ->get()
            ->map(function (object $r) use ($byId) {
                foreach (['spend', 'purchase_value', 'purchases', 'impressions', 'clicks', 'reach'] as $k) {
                    $r->{$k} = $byId[(int) $r->id]->{$k};
                }

                return $r;
            })->all();
    }
}
