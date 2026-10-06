<?php

namespace App\Ads\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Per-ad daily spend and real ROAS for the row/card sparkline (U 3.1, spec 8 performance): one grouped metrics query
 * and one orders query for the whole page, never one per row. The ads are already scoped by the page that lists them;
 * the series is each ad's own history (every buyer-day), like the drawer's numbers.
 */
final class AdDailySeries
{
    public const DAYS = 14;

    public function __construct(private readonly AdsQuery $q) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the DAYS days ending at $to */
    public static function window(CarbonImmutable $to): array
    {
        $to = CarbonImmutable::parse($to->toDateString(), AdsFilter::TIMEZONE)->startOfDay();

        return [$to->subDays(self::DAYS - 1), $to];
    }

    /**
     * $scope (the page's filter) keeps the series to the days the viewer may see: a media buyer only the days they held
     * the account (dated assignments, restrictBuyerId), a picked buyer only that buyer's days. Without it the series is
     * the ad's own whole history (two grouped queries).
     *
     * ROAS is real (EGP) order revenue over spend, so it is null on an account that does not spend in EGP (A9: a USD
     * account would show EGP revenue over USD spend).
     *
     * @param  list<int>  $adIds
     * @return array<int, list<array{date:string, spend:float, roas:?float}>>
     */
    public function forAds(array $adIds, CarbonImmutable $from, CarbonImmutable $to, ?AdsFilter $scope = null): array
    {
        $adIds = array_values(array_unique(array_map('intval', $adIds)));
        if ($adIds === []) {
            return [];
        }
        $f = $scope !== null ? $scope->allSpend()->with(['from' => $from, 'to' => $to]) : new AdsFilter($from, $to);

        $metrics = $scope !== null
            ? $this->q->metrics($f)
            : DB::table('ad_daily_metrics as m')->join('ad_accounts as acc', 'acc.id', '=', 'm.ad_account_id')
                ->whereBetween('m.date', [$f->fromDate(), $f->toDate()]);
        $spend = [];
        $foreign = [];
        $metrics->whereIn('m.ad_id', $adIds)
            ->groupBy('m.ad_id', 'm.date', 'acc.currency')->select(['m.ad_id', 'm.date', 'acc.currency'])->selectRaw('COALESCE(SUM(m.spend), 0) as spend')->get()
            ->each(function (object $r) use (&$spend, &$foreign) {
                $spend[(int) $r->ad_id][substr((string) $r->date, 0, 10)] = (float) $r->spend;
                if (! self::isEgp($r->currency)) {
                    $foreign[(int) $r->ad_id] = true;
                }
            });

        $revenue = [];
        if ($scope !== null) {
            // AdsQuery::orders applies the same scope: the order's Cairo day must be a day the viewer may see.
            foreach ($this->q->orders($f)->whereIn('ad_id', $adIds) as $o) {
                $revenue[(int) $o['ad_id']][$o['date']] = ($revenue[(int) $o['ad_id']][$o['date']] ?? 0.0) + $o['net'];
            }
        } else {
            AdsQuery::realOrders(DB::table('orders as o'))->whereIn('o.ad_id', $adIds)
                ->whereBetween('o.placed_at', [$f->startUtc(), $f->endUtc()])
                ->select(['o.ad_id', 'o.placed_at'])->selectRaw(AdsQuery::netRevenueSql().' as net')->get()
                ->each(function (object $r) use (&$revenue) {
                    $day = $this->q->cairoDate($r->placed_at);
                    $revenue[(int) $r->ad_id][$day] = ($revenue[(int) $r->ad_id][$day] ?? 0.0) + max(0.0, (float) $r->net);
                });
        }

        $out = [];
        foreach ($adIds as $id) {
            $out[$id] = array_map(function (string $day) use ($id, $spend, $revenue, $foreign) {
                $s = round($spend[$id][$day] ?? 0.0, 2);
                $roas = isset($foreign[$id]) ? null : AdsQuery::ratio(round($revenue[$id][$day] ?? 0.0, 2), $s, 2);

                return ['date' => $day, 'spend' => $s, 'roas' => $roas];
            }, $f->days());
        }

        return $out;
    }

    /** Orders are EGP; an account with no currency set is the EGP default (AdsOverview::currency). */
    public static function isEgp(?string $currency): bool
    {
        return $currency === null || $currency === '' || strtoupper($currency) === 'EGP';
    }
}
