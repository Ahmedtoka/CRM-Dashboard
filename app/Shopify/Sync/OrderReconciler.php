<?php

namespace App\Shopify\Sync;

use App\Models\Order;
use App\Shopify\Client\ShopifyClient;
use App\Shopify\Sync\Mappers\Payload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Proves the CRM holds the same orders as Shopify for a date range: Shopify's
 * own `ordersCount` per shop-local day against the CRM's orders placed that day,
 * the same side by side per payment and fulfillment status (so the *latest*
 * state matches too, not only the number), and — for days that differ — the
 * exact order numbers missing from the CRM or extra in it.
 *
 * Read-only on both sides; the "bring them" action is BulkImporter::importOrders().
 */
final class OrderReconciler
{
    private const COUNT = 'query count($q: String) { ordersCount(query: $q, limit: null) { count precision } }';

    private const IDS = 'query ids($q: String, $cursor: String) { orders(first: 250, after: $cursor, query: $q, sortKey: CREATED_AT) { edges { node { id name } } pageInfo { hasNextPage endCursor } } }';

    /** Shopify search value => the CRM's financial_status (displayFinancialStatus, lower-cased). */
    public const FINANCIAL = [
        'paid' => 'paid',
        'pending' => 'pending',
        'partially_paid' => 'partially_paid',
        'refunded' => 'refunded',
        'partially_refunded' => 'partially_refunded',
        'voided' => 'voided',
        'authorized' => 'authorized',
        'expired' => 'expired',
    ];

    /** Shopify search value => the CRM's fulfillment_status (null = unfulfilled). */
    public const FULFILLMENT = [
        'shipped' => 'fulfilled',
        'partial' => 'partial',
        'unshipped' => null,
    ];

    /** Missing-order lookups page every order of a day; cap how many days and names. */
    public const MAX_MISSING_DAYS = 10;

    public const MAX_LISTED = 200;

    public function __construct(private readonly ShopifyClient $client) {}

    /**
     * @return array{
     *   from: string, to: string, timezone: string, checked_at: string, matched: bool,
     *   totals: array{shopify: int, crm: int, diff: int},
     *   days: list<array{date: string, shopify: int, crm: int, diff: int}>,
     *   statuses: list<array{group: string, key: string, shopify: int, crm: int, diff: int}>,
     *   missing: list<array{date: string, id: string, name: ?string}>,
     *   extra: list<array{date: string, id: string, name: ?string}>,
     *   missing_checked_days: list<string>,
     *   breakdown: array{payment_gateway: list<array{key: ?string, count: int}>, province: list<array{key: ?string, count: int}>, shipment: list<array{key: ?string, count: int}>}
     * }
     */
    public function compare(string $from, string $to, bool $withMissing = true): array
    {
        $timezone = BulkImporter::shopTimezone();
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay();

        $local = $this->localPerDay($start, $end, $timezone);
        $days = [];

        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $shopify = $this->count($this->range($day, $day->addDay()));
            $crm = $local[$date] ?? 0;
            $days[] = ['date' => $date, 'shopify' => $shopify, 'crm' => $crm, 'diff' => $crm - $shopify];
        }

        $range = $this->range($start, $end);
        $statuses = [];

        foreach (self::FINANCIAL as $search => $status) {
            $statuses[] = $this->statusRow('financial', $search, "{$range} AND financial_status:{$search}",
                $this->localQuery($start, $end)->where('financial_status', $status));
        }

        foreach (self::FULFILLMENT as $search => $status) {
            $statuses[] = $this->statusRow('fulfillment', $search, "{$range} AND fulfillment_status:{$search}",
                $status === null
                    ? $this->localQuery($start, $end)->whereNull('fulfillment_status')
                    : $this->localQuery($start, $end)->where('fulfillment_status', $status));
        }

        $statuses[] = $this->statusRow('state', 'cancelled', "{$range} AND status:cancelled",
            $this->localQuery($start, $end)->whereNotNull('cancelled_at'));

        $mismatched = array_values(array_filter($days, fn ($d) => $d['diff'] !== 0));
        $missing = [];
        $extra = [];
        $checked = [];

        if ($withMissing) {
            foreach (array_slice($mismatched, 0, self::MAX_MISSING_DAYS) as $row) {
                $day = CarbonImmutable::parse($row['date'], $timezone);
                [$dayMissing, $dayExtra] = $this->diffDay($day, $day->addDay());
                array_push($missing, ...$dayMissing);
                array_push($extra, ...$dayExtra);
                $checked[] = $row['date'];
            }
        }

        $shopifyTotal = (int) array_sum(array_column($days, 'shopify'));
        $crmTotal = (int) array_sum(array_column($days, 'crm'));

        return [
            'from' => $start->toDateString(),
            'to' => $end->subDay()->toDateString(),
            'timezone' => $timezone,
            'checked_at' => now()->toIso8601String(),
            'matched' => $mismatched === [] && collect($statuses)->every(fn ($s) => $s['diff'] === 0),
            'totals' => ['shopify' => $shopifyTotal, 'crm' => $crmTotal, 'diff' => $crmTotal - $shopifyTotal],
            'days' => $days,
            'statuses' => $statuses,
            'missing' => array_slice($missing, 0, self::MAX_LISTED),
            'extra' => array_slice($extra, 0, self::MAX_LISTED),
            'missing_checked_days' => $checked,
            'breakdown' => [
                'payment_gateway' => $this->groupCount($start, $end, 'payment_gateway'),
                'province' => $this->groupCount($start, $end, 'shipping_province'),
                'shipment' => $this->groupCount($start, $end, 'shipment_status'),
            ],
        ];
    }

    private function statusRow(string $group, string $key, string $query, Builder $local): array
    {
        $shopify = $this->count($query);
        $crm = $local->count();

        return ['group' => $group, 'key' => $key, 'shopify' => $shopify, 'crm' => $crm, 'diff' => $crm - $shopify];
    }

    private function count(string $query): int
    {
        return (int) ($this->client->query(self::COUNT, ['q' => $query])['ordersCount']['count'] ?? 0);
    }

    private function range(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return "created_at:>='{$start->toIso8601String()}' AND created_at:<'{$end->toIso8601String()}'";
    }

    /** CRM orders that exist in Shopify, placed in [start, end). */
    private function localQuery(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Order::query()
            ->whereNotNull('shopify_order_id')
            ->where('placed_at', '>=', $start->utc())
            ->where('placed_at', '<', $end->utc());
    }

    /** @return array<string, int> shop-local date => orders placed */
    private function localPerDay(CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $counts = [];

        $this->localQuery($start, $end)->select(['id', 'placed_at'])->toBase()->orderBy('id')
            ->chunk(5000, function ($rows) use (&$counts, $timezone) {
                foreach ($rows as $row) {
                    $date = CarbonImmutable::parse($row->placed_at, 'UTC')->setTimezone($timezone)->toDateString();
                    $counts[$date] = ($counts[$date] ?? 0) + 1;
                }
            });

        return $counts;
    }

    /** @return list<array{key: ?string, count: int}> */
    private function groupCount(CarbonImmutable $start, CarbonImmutable $end, string $column): array
    {
        return $this->localQuery($start, $end)
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->limit(40)
            ->toBase()
            ->get()
            ->map(fn ($row) => ['key' => $row->{$column}, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * Every Shopify order of the window against the CRM: missing = in Shopify but
     * nowhere in the CRM; extra = placed in the window in the CRM but not in
     * Shopify's list for it (deleted in Shopify, or its date moved).
     *
     * @return array{0: list<array{date: string, id: string, name: ?string}>, 1: list<array{date: string, id: string, name: ?string}>}
     */
    private function diffDay(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $remote = [];
        $cursor = null;

        do {
            $page = $this->client->query(self::IDS, ['q' => $this->range($start, $end), 'cursor' => $cursor])['orders'] ?? [];

            foreach ($page['edges'] ?? [] as $edge) {
                $id = Payload::id($edge['node']['id'] ?? null);

                if ($id !== null) {
                    $remote[$id] = $edge['node']['name'] ?? null;
                }
            }

            $cursor = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        $known = [];

        foreach (array_chunk(array_keys($remote), 1000) as $chunk) {
            $known += Order::query()->whereIn('shopify_order_id', array_map('strval', $chunk))->pluck('shopify_order_id', 'shopify_order_id')->all();
        }

        $date = $start->toDateString();
        $missing = [];

        foreach ($remote as $id => $name) {
            if (! isset($known[(string) $id])) {
                $missing[] = ['date' => $date, 'id' => (string) $id, 'name' => $name];
            }
        }

        $extra = $this->localQuery($start, $end)
            ->get(['shopify_order_id', 'shopify_order_name'])
            ->reject(fn (Order $o) => array_key_exists((string) $o->shopify_order_id, $remote))
            ->map(fn (Order $o) => ['date' => $date, 'id' => (string) $o->shopify_order_id, 'name' => $o->shopify_order_name])
            ->values()
            ->all();

        return [$missing, $extra];
    }
}
