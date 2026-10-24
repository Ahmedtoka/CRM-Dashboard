<?php

namespace App\Orders\Reports;

use App\Analytics\MetricsService;
use App\Cases\CaseSummary;
use App\Cases\Returns\ReturnLifecycle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportCase;
use App\Orders\OrdersAnalytics;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Return KPIs for Reports (round 2026-10-24, for x3's report pages): the return rate of a period and per product.
 *
 * - A "return" is a support case of a return type (SupportCase::RETURN_TYPES) created in the period, cancelled
 *   ones excluded (the customer withdrew, or policy refused). Exchanges are counted apart.
 * - The rate's denominator is the real orders placed in the period (not cancelled/failed; OrdersAnalytics::ORDER_DATE).
 * - Per product: the pieces the customer picked in the return flow (`data.selected_items`, by title) against the
 *   units of that title sold in the period. Titles are compared as written on the order line, as the flow stores them.
 * - Refunded cash: the sum of `refund_amount` of returns picked up in the period.
 *
 * Period bounds are inclusive instants (the caller passes Cairo day bounds).
 */
final class ReturnStats
{
    public const TOP = 10;

    /**
     * @return array{
     *   orders:int, returns:int, exchanges:int, cancelled:int, rate:float, refunded_total:float,
     *   by_stage:array<string,int>, avg_days_to_pickup:?float, avg_days_to_close:?float
     * }
     */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $cases = $this->cases($from, $to);
        $live = $cases->filter(fn (SupportCase $c) => ReturnLifecycle::stageOf($c) !== 'cancelled');
        $orders = Order::query()->whereNotIn('status', MetricsService::EXCLUDED_ORDER_STATUSES)
            ->whereRaw(OrdersAnalytics::ORDER_DATE.' between ? and ?', [$from, $to])->count();

        $returns = $live->filter(fn (SupportCase $c) => ! ReturnLifecycle::isExchange($c))->count();
        $exchanges = $live->count() - $returns;

        $byStage = array_fill_keys(ReturnLifecycle::STAGES, 0);
        foreach ($cases as $c) {
            $byStage[ReturnLifecycle::stageOf($c)]++;
        }

        $pickups = SupportCase::query()->whereIn('type', SupportCase::RETURN_TYPES)
            ->whereNotNull('refund_amount')
            ->whereHas('events', fn ($q) => $q->where('stage', 'picked_up')->whereBetween('happened_at', [$from, $to]))
            ->sum('refund_amount');

        return [
            'orders' => $orders,
            'returns' => $returns,
            'exchanges' => $exchanges,
            'cancelled' => $cases->count() - $live->count(),
            'rate' => $orders > 0 ? round($live->count() / $orders, 4) : 0.0,
            'refunded_total' => (float) $pickups,
            'by_stage' => $byStage,
            'avg_days_to_pickup' => $this->averageDays($live, 'picked_up'),
            'avg_days_to_close' => $this->averageDays($live, 'closed'),
        ];
    }

    /**
     * @return list<array{title:string, returned_units:int, sold_units:int, rate:?float, cases:int}>
     */
    public function byProduct(CarbonInterface $from, CarbonInterface $to, int $limit = self::TOP): array
    {
        $rows = [];

        foreach ($this->cases($from, $to) as $case) {
            if (ReturnLifecycle::stageOf($case) === 'cancelled') {
                continue;
            }

            foreach (CaseSummary::selectedItems(is_array($case->data) ? $case->data : []) as $item) {
                $key = mb_strtolower(trim($item['title']));
                $rows[$key] ??= ['title' => trim($item['title']), 'returned_units' => 0, 'sold_units' => 0, 'rate' => null, 'cases' => 0];
                $rows[$key]['returned_units'] += $item['qty'];
                $rows[$key]['cases']++;
            }
        }

        if ($rows === []) {
            return [];
        }

        $sold = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', MetricsService::EXCLUDED_ORDER_STATUSES)
            ->where('orders.is_load_test', false)
            ->whereRaw(OrdersAnalytics::ORDER_DATE.' between ? and ?', [$from, $to])
            ->whereIn('order_items.title', array_column($rows, 'title'))
            ->selectRaw('order_items.title as title, sum(order_items.qty) as units')
            ->groupBy('order_items.title')
            ->pluck('units', 'title');

        foreach ($sold as $title => $units) {
            $key = mb_strtolower(trim((string) $title));
            if (isset($rows[$key])) {
                $rows[$key]['sold_units'] += (int) $units;
            }
        }

        foreach ($rows as &$row) {
            $row['rate'] = $row['sold_units'] > 0 ? round(min(1, $row['returned_units'] / $row['sold_units']), 4) : null;
        }
        unset($row);

        usort($rows, fn ($a, $b) => [$b['returned_units'], $b['cases']] <=> [$a['returned_units'], $a['cases']]);

        return array_slice(array_values($rows), 0, $limit);
    }

    /** @return Collection<int, SupportCase> */
    private function cases(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return SupportCase::query()->with('events')
            ->whereIn('type', SupportCase::RETURN_TYPES)
            ->whereBetween('created_at', [$from, $to])
            ->get();
    }

    /** @param  Collection<int, SupportCase>  $cases */
    private function averageDays(Collection $cases, string $stage): ?float
    {
        $days = $cases->map(function (SupportCase $c) use ($stage) {
            $event = $c->events->firstWhere('stage', $stage);

            return $event === null ? null : $c->created_at->diffInSeconds($event->happened_at) / 86400;
        })->filter(fn ($d) => $d !== null);

        return $days->isEmpty() ? null : round((float) $days->avg(), 1);
    }
}
