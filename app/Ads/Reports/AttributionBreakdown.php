<?php

namespace App\Ads\Reports;

use App\Ads\Attribution\OrderAttribution;
use Illuminate\Support\Facades\DB;

/**
 * «كام أوردر من الإعلانات فعلاً» (round 3): the real orders of the filter's range split by the rule that attributed them
 * (chat referral, utm ad, utm ad set, utm campaign) and the ones no rule could attribute, with Shopify net revenue
 * (AdsQuery::netRevenueSql, refunds counted once). It is the whole store, not a buyer's slice: managers only (the
 * controller passes it to them alone), which also makes the unattributed share honest.
 */
final class AttributionBreakdown
{
    /**
     * @return array{rules: list<array{rule: string, orders: int, revenue: float}>, total_orders: int, attributed_orders: int, unattributed_orders: int, attributed_pct: ?float, total_revenue: float, attributed_revenue: float, unattributed_revenue: float}
     */
    public function build(AdsFilter $f): array
    {
        $rows = AdsQuery::realOrders(DB::table('orders as o'))
            ->whereBetween('o.placed_at', [$f->startUtc(), $f->endUtc()])
            ->groupBy('o.ad_attribution')
            ->selectRaw('o.ad_attribution as rule, COUNT(*) as orders, COALESCE(SUM('.AdsQuery::netRevenueSql().'), 0) as revenue')
            ->get()->keyBy(fn (object $r) => (string) ($r->rule ?? ''));

        $rules = [];
        $attributed = 0;
        $attributedRevenue = 0.0;
        foreach (OrderAttribution::RULES as $rule) {
            $r = $rows->get($rule);
            $orders = (int) ($r->orders ?? 0);
            $revenue = round((float) ($r->revenue ?? 0), 2);
            $rules[] = ['rule' => $rule, 'orders' => $orders, 'revenue' => $revenue];
            $attributed += $orders;
            $attributedRevenue += $revenue;
        }
        $none = $rows->get('');
        $unattributed = (int) ($none->orders ?? 0);
        $unattributedRevenue = round((float) ($none->revenue ?? 0), 2);
        $total = $attributed + $unattributed;

        return [
            'rules' => $rules,
            'total_orders' => $total,
            'attributed_orders' => $attributed,
            'unattributed_orders' => $unattributed,
            'attributed_pct' => $total > 0 ? round($attributed / $total * 100, 1) : null,
            'total_revenue' => round($attributedRevenue + $unattributedRevenue, 2),
            'attributed_revenue' => round($attributedRevenue, 2),
            'unattributed_revenue' => $unattributedRevenue,
        ];
    }
}
