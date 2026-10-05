<?php

namespace App\Ads\Reports;

use App\Enums\OrderSource;
use Illuminate\Support\Facades\DB;

/**
 * Spec 1.3: the same range seen three ways.
 * - store: every Shopify/CRM order placed in range that is real by the CRM side's definition (AdsQuery::realOrders and
 *   netRevenueSql: not awaiting payment/cancelled/failed/courier-returned, refunds counted once); store-wide, it ignores
 *   the account/buyer filters;
 * - crm: the ad-attributed real orders (AdsQuery::orders), plus how many of them were chat orders;
 * - platform: what the ad platforms report in ad_daily_metrics.
 * A gap `x_vs_y` is x − y; its percentage is relative to y.
 */
final class RevenueSummary
{
    public function __construct(private readonly AdsQuery $q, private readonly AdsOverview $overview) {}

    /**
     * @return array{currency:string, mixed_currencies:bool, spend:?float, spend_tax:?float, store: ?array{orders:int, revenue:?float}, crm: array{orders:float, revenue:?float, chat_orders:int}, platform: array{purchases:float, revenue:?float}, roas: array{store:?float, crm:?float, platform:?float}, gaps: array{platform_vs_crm: ?float, crm_vs_store: ?float, platform_vs_crm_pct:?float, crm_vs_store_pct:?float}}
     */
    public function build(AdsFilter $f, bool $withStore): array
    {
        $f = $f->allSpend();
        $d = $this->q->deriveWithControl($f, $this->q->sums($f)->first() ?? []);
        $orders = $this->q->orders($f);
        $crmRevenue = round((float) $orders->sum('net'), 2);

        $store = $withStore ? $this->store($f) : null;

        $currency = $this->overview->currency($f);
        $mixed = $currency === AdsOverview::MIXED;

        $gap = fn (float $x, float $y): array => [round($x - $y, 2), AdsQuery::ratio($x - $y, $y, 4)];
        [$pvc, $pvcPct] = $gap($d['purchase_value'], $crmRevenue);
        [$cvs, $cvsPct] = $store === null ? [null, null] : $gap($crmRevenue, $store['revenue']);

        $out = [
            'currency' => $currency,
            'mixed_currencies' => $mixed,
            'spend' => $d['spend'],
            'spend_tax' => $d['spend_tax'],
            'store' => $store,
            'crm' => [
                'orders' => (float) $orders->count(),
                'revenue' => $crmRevenue,
                'chat_orders' => $this->chatOrders($orders->pluck('id')->all()),
            ],
            'platform' => ['purchases' => $d['purchases'], 'revenue' => $d['purchase_value']],
            'roas' => [
                'store' => $store === null ? null : AdsQuery::ratio($store['revenue'], $d['spend'], 2),
                'crm' => AdsQuery::ratio($crmRevenue, $d['spend'], 2),
                'platform' => $d['roas'],
            ],
            'gaps' => [
                'platform_vs_crm' => $pvc, 'crm_vs_store' => $cvs,
                'platform_vs_crm_pct' => $pvcPct, 'crm_vs_store_pct' => $cvsPct,
            ],
        ];

        if ($mixed) {
            // A9: no figure here may add one currency to another (store revenue is EGP, the platforms report in theirs).
            $out['spend'] = $out['spend_tax'] = null;
            $out['store'] = $store === null ? null : ['orders' => $store['orders'], 'revenue' => null];
            $out['crm']['revenue'] = null;
            $out['platform']['revenue'] = null;
            $out['roas'] = ['store' => null, 'crm' => null, 'platform' => null];
            $out['gaps'] = array_fill_keys(array_keys($out['gaps']), null);
        }

        return $out;
    }

    /** @return array{orders:int, revenue:float} */
    private function store(AdsFilter $f): array
    {
        $row = AdsQuery::realOrders(DB::table('orders as o'))
            ->whereNull('o.cancelled_at')
            ->whereBetween('o.placed_at', [$f->startUtc(), $f->endUtc()])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM('.AdsQuery::netRevenueSql().'), 0) as revenue')
            ->first();

        return ['orders' => (int) $row->orders, 'revenue' => round((float) $row->revenue, 2)];
    }

    /** @param  list<int>  $ids */
    private function chatOrders(array $ids): int
    {
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += DB::table('orders')->whereIn('id', $chunk)->where('source', OrderSource::Chat->value)->count();
        }

        return $n;
    }
}
