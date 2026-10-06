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
     * @param  list<int>  $adIds
     * @return array<int, list<array{date:string, spend:float, roas:?float}>>
     */
    public function forAds(array $adIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $adIds = array_values(array_unique(array_map('intval', $adIds)));
        if ($adIds === []) {
            return [];
        }
        $f = new AdsFilter($from, $to);

        $spend = [];
        DB::table('ad_daily_metrics as m')->whereIn('m.ad_id', $adIds)->whereBetween('m.date', [$f->fromDate(), $f->toDate()])
            ->groupBy('m.ad_id', 'm.date')->select(['m.ad_id', 'm.date'])->selectRaw('COALESCE(SUM(m.spend), 0) as spend')->get()
            ->each(function (object $r) use (&$spend) {
                $spend[(int) $r->ad_id][substr((string) $r->date, 0, 10)] = (float) $r->spend;
            });

        $revenue = [];
        AdsQuery::realOrders(DB::table('orders as o'))->whereIn('o.ad_id', $adIds)
            ->whereBetween('o.placed_at', [$f->startUtc(), $f->endUtc()])
            ->select(['o.ad_id', 'o.placed_at'])->selectRaw(AdsQuery::netRevenueSql().' as net')->get()
            ->each(function (object $r) use (&$revenue) {
                $day = $this->q->cairoDate($r->placed_at);
                $revenue[(int) $r->ad_id][$day] = ($revenue[(int) $r->ad_id][$day] ?? 0.0) + max(0.0, (float) $r->net);
            });

        $out = [];
        foreach ($adIds as $id) {
            $out[$id] = array_map(function (string $day) use ($id, $spend, $revenue) {
                $s = round($spend[$id][$day] ?? 0.0, 2);

                return ['date' => $day, 'spend' => $s, 'roas' => AdsQuery::ratio(round($revenue[$id][$day] ?? 0.0, 2), $s, 2)];
            }, $f->days());
        }

        return $out;
    }
}
