<?php

namespace App\Orders;

use App\Analytics\MetricsService;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * The «الإعلانات» tab of /orders (fresh-orders F5): the filtered, role-scoped orders grouped by the ad they are
 * credited to (orders.ad_id), with the ad's platform, campaign and ad set for the platform → campaign → ad set → ad
 * tree. Two grouped queries (orders + revenue, units); unattributed orders come back as one `direct` row (ad_id null).
 * Orders = every matching order; revenue and units = real orders only (as on the analytics tab).
 */
final class OrdersByAd
{
    /**
     * @param  Builder<Order>  $base
     * @return list<array{ad_id:?int, ad:?string, thumbnail_url:?string, external_id:?string, platform:?string, campaign_id:?int, campaign:?string, ad_set_id:?int, ad_set:?string, orders:int, revenue:float, units:int}>
     */
    public function rows(Builder $base): array
    {
        [$real, $b] = OrdersAnalytics::realSql();

        $rows = (clone $base)->toBase()
            ->leftJoin('ads', 'ads.id', '=', 'orders.ad_id')
            ->leftJoin('ad_accounts', 'ad_accounts.id', '=', 'ads.ad_account_id')
            ->leftJoin('ad_campaigns', 'ad_campaigns.id', '=', 'ads.ad_campaign_id')
            ->leftJoin('ad_sets', 'ad_sets.id', '=', 'ads.ad_set_id')
            ->groupBy('orders.ad_id', 'ads.name', 'ads.thumbnail_url', 'ads.external_id', 'ad_accounts.platform', 'ad_accounts.external_id', 'ads.ad_campaign_id', 'ad_campaigns.name', 'ads.ad_set_id', 'ad_sets.name')
            ->selectRaw('orders.ad_id as ad_id, ads.name as ad, ads.thumbnail_url as thumbnail_url, ads.external_id as external_id, '
                .'ad_accounts.platform as platform, ad_accounts.external_id as account_external_id, ads.ad_campaign_id as campaign_id, ad_campaigns.name as campaign, ads.ad_set_id as ad_set_id, ad_sets.name as ad_set, '
                ."count(*) as n, sum(case when {$real} then orders.total else 0 end) as revenue", $b)
            ->get();

        $units = (clone $base)->toBase()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereNotIn('orders.status', MetricsService::EXCLUDED_ORDER_STATUSES)
            ->groupBy('orders.ad_id')
            ->selectRaw('orders.ad_id as ad_id, sum(order_items.qty) as units')
            ->pluck('units', 'ad_id');

        return $rows->map(fn (object $r) => [
            'ad_id' => $r->ad_id === null ? null : (int) $r->ad_id,
            'ad' => $r->ad,
            'thumbnail_url' => $r->thumbnail_url,
            'external_id' => $r->external_id === null ? null : (string) $r->external_id,
            'platform' => $r->ad_id === null ? 'direct' : ($r->platform ?? null),
            'account_external_id' => $r->account_external_id === null ? null : (string) $r->account_external_id,
            'campaign_id' => $r->campaign_id === null ? null : (int) $r->campaign_id,
            'campaign' => $r->campaign,
            'ad_set_id' => $r->ad_set_id === null ? null : (int) $r->ad_set_id,
            'ad_set' => $r->ad_set,
            'orders' => (int) $r->n,
            'revenue' => round((float) $r->revenue, 2),
            'units' => (int) ($units[$r->ad_id ?? ''] ?? 0),
        ])->sortByDesc('orders')->values()->all();
    }
}
