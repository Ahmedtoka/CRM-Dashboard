<?php

namespace App\Orders;

use App\Analytics\MetricsService;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The «تحليلات» tab of /orders (fresh-orders F5): every figure is one grouped SQL query over the same filtered,
 * role-scoped order set the list shows (OrderEndpoints::orderBaseQuery), never a loop over orders.
 *
 * - Order date = coalesce(placed_at, created_at) (imported Shopify orders keep the store time in placed_at).
 * - "orders" counts every matching order; revenue, AOV, customers, products and the day chart count only real
 *   orders (not cancelled / failed, MetricsService::EXCLUDED_ORDER_STATUSES).
 * - Governorate = GovernorateKey: the province code, else the Shopify province name mapped back to its code
 *   (so one governorate is never split between a code and a name); Arabic label from crm.eg_provinces.
 *   District = shipping_city: the order/address schema has no district column; Shopify's "city" line is where
 *   Egyptian stores put the district/area.
 * - Days are Cairo calendar days: the rows are grouped by UTC hour in SQL and folded into Cairo days here, so the
 *   DST switch is right on both sqlite and MySQL.
 */
final class OrdersAnalytics
{
    public const ORDER_DATE = 'coalesce(orders.placed_at, orders.created_at)';

    public const TOP = 8;

    private const TZ = 'Africa/Cairo';

    /**
     * @param  Builder<Order>  $base  the filtered list query, without eager loads or ordering
     */
    public function build(Builder $base, ?string $fromDate, ?string $toDate): array
    {
        return [
            'totals' => $this->totals($base, $fromDate),
            'governorates' => $this->governorates($base),
            'districts' => $this->districts($base),
            'frequency' => $this->frequency($base),
            'products' => $this->products($base),
            'statuses' => $this->statuses($base),
            // No period (a triage view): no day chart.
            'days' => $fromDate !== null && $toDate !== null ? $this->days($base, $fromDate, $toDate) : [],
        ];
    }

    /** The «real» predicate as SQL + bindings (for CASE expressions). */
    public static function realSql(): array
    {
        $ex = MetricsService::EXCLUDED_ORDER_STATUSES;

        return ['orders.status not in ('.implode(',', array_fill(0, count($ex), '?')).')', $ex];
    }

    /**
     * @return array{orders:int, real_orders:int, revenue:float, aov:float, customers:int, new_customers:int, repeat_customers:int, units:int}
     */
    public function totals(Builder $base, ?string $fromDate = null): array
    {
        [$real, $b] = self::realSql();
        $row = (clone $base)->toBase()
            ->selectRaw("count(*) as orders, sum(case when {$real} then 1 else 0 end) as real_orders, "
                ."sum(case when {$real} then orders.total else 0 end) as revenue, "
                ."count(distinct case when {$real} then orders.customer_id end) as customers", [...$b, ...$b, ...$b])
            ->first();

        $units = (int) (clone $base)->toBase()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereNotIn('orders.status', MetricsService::EXCLUDED_ORDER_STATUSES)
            ->sum('order_items.qty');

        $realOrders = (int) ($row->real_orders ?? 0);
        $revenue = round((float) ($row->revenue ?? 0), 2);
        [$new, $repeat] = $fromDate !== null ? $this->newVsRepeat($base, $fromDate) : [0, 0];

        return [
            'orders' => (int) ($row->orders ?? 0),
            'real_orders' => $realOrders,
            'revenue' => $revenue,
            'aov' => $realOrders > 0 ? round($revenue / $realOrders, 2) : 0.0,
            'customers' => (int) ($row->customers ?? 0),
            'new_customers' => $new,
            'repeat_customers' => $repeat,
            'units' => $units,
        ];
    }

    /**
     * New = the customer's first real order ever falls inside the range; repeat = they ordered before it.
     * One grouped subquery (min order date per customer of the range), counted in SQL.
     *
     * @return array{0:int, 1:int}
     */
    private function newVsRepeat(Builder $base, string $fromDate): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $fromDate, self::TZ)->startOfDay()->utc()->toDateTimeString();
        $inRange = (clone $base)->toBase()
            ->whereNotIn('orders.status', MetricsService::EXCLUDED_ORDER_STATUSES)
            ->whereNotNull('orders.customer_id')
            ->select('orders.customer_id');

        $firsts = DB::table('orders')
            ->whereNotIn('status', MetricsService::EXCLUDED_ORDER_STATUSES)->where('is_load_test', false)
            ->whereIn('customer_id', $inRange)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, min(coalesce(placed_at, created_at)) as first_at');

        $row = DB::query()->fromSub($firsts, 'f')
            ->selectRaw('sum(case when f.first_at >= ? then 1 else 0 end) as new_c, sum(case when f.first_at < ? then 1 else 0 end) as repeat_c', [$start, $start])
            ->first();

        return [(int) ($row->new_c ?? 0), (int) ($row->repeat_c ?? 0)];
    }

    /** @return list<array{key:?string, label:?string, orders:int, revenue:float}> top N + «rest» (key `_rest`) */
    public function governorates(Builder $base): array
    {
        $names = (array) config('crm.eg_provinces', []);
        $rows = $this->grouped($base, GovernorateKey::sql());

        return $this->topWithRest(array_map(fn (array $r) => $r + [
            'label' => $r['key'] === null ? null : ($names[strtoupper((string) $r['key'])] ?? $r['key']),
        ], $rows));
    }

    /** @return list<array{key:?string, label:?string, orders:int, revenue:float}> */
    public function districts(Builder $base): array
    {
        $rows = $this->grouped($base, "nullif(trim(orders.shipping_city), '')");

        return $this->topWithRest(array_map(fn (array $r) => $r + ['label' => $r['key']], $rows));
    }

    /** @return list<array{key:?string, orders:int, revenue:float}> ordered by orders desc */
    private function grouped(Builder $base, string $expr): array
    {
        [$real, $b] = self::realSql();

        return (clone $base)->toBase()
            ->selectRaw("{$expr} as k, count(*) as n, sum(case when {$real} then orders.total else 0 end) as revenue", $b)
            ->groupByRaw($expr)
            ->orderByDesc('n')
            ->orderBy('k')
            ->get()
            ->map(fn (object $r) => ['key' => $r->k === null ? null : (string) $r->k, 'orders' => (int) $r->n, 'revenue' => round((float) $r->revenue, 2)])
            ->all();
    }

    private function topWithRest(array $rows): array
    {
        if (count($rows) <= self::TOP + 1) {
            return $rows;
        }

        $top = array_slice($rows, 0, self::TOP);
        $rest = array_slice($rows, self::TOP);
        $top[] = [
            'key' => '_rest',
            'orders' => array_sum(array_column($rest, 'orders')),
            'revenue' => round(array_sum(array_column($rest, 'revenue')), 2),
            'label' => null,
            'count' => count($rest),
        ];

        return $top;
    }

    /**
     * Customers by how many real orders they placed in the range: 1, 2, 3+, plus the repeat buyers (2+) by name.
     *
     * @return array{one:int, two:int, three_plus:int, customers: list<array{id:int, name:?string, phone:?string, orders:int}>}
     */
    public function frequency(Builder $base): array
    {
        $per = (clone $base)->toBase()
            ->whereNotIn('orders.status', MetricsService::EXCLUDED_ORDER_STATUSES)
            ->whereNotNull('orders.customer_id')
            ->groupBy('orders.customer_id')
            ->selectRaw('orders.customer_id as cid, count(*) as n');

        $buckets = DB::query()->fromSub($per, 'p')
            ->selectRaw('sum(case when p.n = 1 then 1 else 0 end) as one, sum(case when p.n = 2 then 1 else 0 end) as two, sum(case when p.n >= 3 then 1 else 0 end) as three_plus')
            ->first();

        $customers = DB::query()->fromSub($per, 'p')
            ->join('customers', 'customers.id', '=', 'p.cid')
            ->where('p.n', '>=', 2)
            ->orderByDesc('p.n')->orderBy('p.cid')
            ->limit(20)
            ->get(['customers.id', 'customers.name', 'customers.phone', 'p.n'])
            ->map(fn (object $r) => ['id' => (int) $r->id, 'name' => $r->name, 'phone' => $r->phone, 'orders' => (int) $r->n])
            ->all();

        return [
            'one' => (int) ($buckets->one ?? 0),
            'two' => (int) ($buckets->two ?? 0),
            'three_plus' => (int) ($buckets->three_plus ?? 0),
            'customers' => $customers,
        ];
    }

    /** @return list<array{title:string, image_url:?string, units:int, revenue:float}> top products by units sold */
    public function products(Builder $base, int $limit = 10): array
    {
        return (clone $base)->toBase()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereNotIn('orders.status', MetricsService::EXCLUDED_ORDER_STATUSES)
            ->groupBy('order_items.title')
            ->selectRaw('order_items.title as title, max(order_items.image_url) as image_url, sum(order_items.qty) as units, sum(order_items.qty * order_items.price) as revenue')
            ->orderByDesc('units')->orderBy('order_items.title')
            ->limit($limit)
            ->get()
            ->map(fn (object $r) => ['title' => (string) $r->title, 'image_url' => $r->image_url, 'units' => (int) $r->units, 'revenue' => round((float) $r->revenue, 2)])
            ->all();
    }

    /**
     * One state per order, Shopify first: cancelled, failed (never reached Shopify), delivered, shipped (fulfilled),
     * awaiting payment, confirmed.
     *
     * @return list<array{key:string, orders:int}>
     */
    public function statuses(Builder $base): array
    {
        $case = "case when orders.status = 'cancelled' or orders.cancelled_at is not null then 'cancelled' "
            ."when orders.status = 'failed' then 'failed' "
            ."when orders.delivered_at is not null or orders.shipment_status = 'delivered' then 'delivered' "
            ."when orders.fulfillment_status in ('fulfilled', 'partial') then 'shipped' "
            ."when orders.status = 'awaiting_payment' then 'awaiting_payment' "
            ."else 'confirmed' end";

        return (clone $base)->toBase()
            ->selectRaw("{$case} as k, count(*) as n")
            ->groupByRaw($case)
            ->orderByDesc('n')
            ->get()
            ->map(fn (object $r) => ['key' => (string) $r->k, 'orders' => (int) $r->n])
            ->all();
    }

    /** @return list<array{date:string, orders:int, revenue:float}> every Cairo day of the range, zeros included */
    public function days(Builder $base, string $fromDate, string $toDate): array
    {
        [$real, $b] = self::realSql();
        $hour = 'substr('.self::ORDER_DATE.', 1, 13)';

        $rows = (clone $base)->toBase()
            ->selectRaw("{$hour} as h, count(*) as n, sum(case when {$real} then orders.total else 0 end) as revenue", $b)
            ->groupByRaw($hour)
            ->get();

        $days = [];
        for ($d = CarbonImmutable::parse($fromDate); $d->toDateString() <= $toDate; $d = $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'orders' => 0, 'revenue' => 0.0];
        }

        foreach ($rows as $r) {
            $day = CarbonImmutable::createFromFormat('Y-m-d H', (string) $r->h, 'UTC')->setTimezone(self::TZ)->toDateString();
            if (isset($days[$day])) {
                $days[$day]['orders'] += (int) $r->n;
                $days[$day]['revenue'] = round($days[$day]['revenue'] + (float) $r->revenue, 2);
            }
        }

        return array_values($days);
    }
}
