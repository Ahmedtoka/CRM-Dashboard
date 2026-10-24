<?php

namespace App\Ads\Attribution;

use App\Ads\AdsSettings;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «تكلفة الإعلان لكل أوردر» (round 3, 2026-10-24): orders.ad_cost_per_order.
 *
 * Definition, per real order attributed to an ad object, on the order's Cairo day D:
 *   cost = spend WITH tax of the narrowest attributed object on D ÷ the number of real orders attributed to that object
 *          (or anything below it) on D.
 *   - an ad-level order (ad_id set):            its ad's spend on D ÷ the ad's orders on D;
 *   - an ad-set-level order (ad_set_id, no ad): the ad set's spend on D ÷ the orders of the ad set and of its ads on D;
 *   - a campaign-level order:                   the campaign's spend on D ÷ every order of the campaign on D.
 * Cancelled, unpaid, failed and courier-returned orders carry no cost (they are not real orders) and are not counted in
 * the denominator. A day with orders but no spend row gives 0.00 (the order was free that day); no attribution = null.
 * The sibling session x3 adds the service cost; both land on the order page (docs/round-2026-10-24/cost-fields.md).
 */
final class AdCostPerOrder
{
    public function __construct(private readonly AdsSettings $settings) {}

    /** Recomputes the cost of every attributed real order placed in [from, to]; returns how many orders were written. */
    public function run(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $tax = 1 + $this->settings->taxRate();
        $tz = AdsFilter::TIMEZONE;

        $orders = AdsQuery::realOrders(DB::table('orders as o'))
            ->whereBetween('o.placed_at', [$from, $to])
            ->where(fn ($w) => $w->whereNotNull('o.ad_id')->orWhereNotNull('o.ad_set_id')->orWhereNotNull('o.ad_campaign_id'))
            ->get(['o.id', 'o.placed_at', 'o.ad_id', 'o.ad_set_id', 'o.ad_campaign_id', 'o.ad_cost_per_order']);
        if ($orders->isEmpty()) {
            return 0;
        }

        // Orders per (object, day), the object's children counted in.
        $counts = ['ad' => [], 'set' => [], 'campaign' => []];
        $days = [];
        foreach ($orders as $o) {
            $day = CarbonImmutable::parse((string) $o->placed_at, 'UTC')->setTimezone($tz)->toDateString();
            $o->day = $day;
            $days[$day] = true;
            if ($o->ad_id !== null) {
                $counts['ad'][$o->ad_id][$day] = ($counts['ad'][$o->ad_id][$day] ?? 0) + 1;
            }
            if ($o->ad_set_id !== null) {
                $counts['set'][$o->ad_set_id][$day] = ($counts['set'][$o->ad_set_id][$day] ?? 0) + 1;
            }
            if ($o->ad_campaign_id !== null) {
                $counts['campaign'][$o->ad_campaign_id][$day] = ($counts['campaign'][$o->ad_campaign_id][$day] ?? 0) + 1;
            }
        }

        // Spend per ad per day over the days touched, then rolled up to the ad set and the campaign.
        $dayList = array_keys($days);
        $spend = ['ad' => [], 'set' => [], 'campaign' => []];
        DB::table('ad_daily_metrics as m')->join('ads as ad', 'ad.id', '=', 'm.ad_id')
            ->whereBetween('m.date', [min($dayList), max($dayList)])
            ->groupBy('m.ad_id', 'm.date', 'ad.ad_set_id', 'ad.ad_campaign_id')
            ->get(['m.ad_id', 'm.date', 'ad.ad_set_id', 'ad.ad_campaign_id', DB::raw('COALESCE(SUM(m.spend), 0) as spend')])
            ->each(function (object $r) use (&$spend) {
                $day = substr((string) $r->date, 0, 10);
                $spend['ad'][$r->ad_id][$day] = ($spend['ad'][$r->ad_id][$day] ?? 0) + (float) $r->spend;
                if ($r->ad_set_id !== null) {
                    $spend['set'][$r->ad_set_id][$day] = ($spend['set'][$r->ad_set_id][$day] ?? 0) + (float) $r->spend;
                }
                if ($r->ad_campaign_id !== null) {
                    $spend['campaign'][$r->ad_campaign_id][$day] = ($spend['campaign'][$r->ad_campaign_id][$day] ?? 0) + (float) $r->spend;
                }
            });

        $written = 0;
        foreach ($orders as $o) {
            [$kind, $id] = match (true) {
                $o->ad_id !== null => ['ad', $o->ad_id],
                $o->ad_set_id !== null => ['set', $o->ad_set_id],
                default => ['campaign', $o->ad_campaign_id],
            };
            $n = max(1, (int) ($counts[$kind][$id][$o->day] ?? 1));
            $cost = round((float) ($spend[$kind][$id][$o->day] ?? 0) * $tax / $n, 2);
            if ($o->ad_cost_per_order !== null && abs((float) $o->ad_cost_per_order - $cost) < 0.005) {
                continue;
            }
            DB::table('orders')->where('id', $o->id)->update(['ad_cost_per_order' => $cost]);
            $written++;
        }

        return $written;
    }
}
