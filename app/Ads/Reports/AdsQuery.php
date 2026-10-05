<?php

namespace App\Ads\Reports;

use App\Ads\AdsSettings;
use App\Ads\Buyers\BuyerResolver;
use App\Ads\Control\AdWriteService;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\AdAccountAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shared building blocks of the Ads reports (spec section 5):
 * - metric rows (ad_daily_metrics m + buyer of the row's day + ads ad + ad_accounts acc), filtered;
 * - real orders (ad- or campaign-attributed, not awaiting payment/cancelled/failed/courier-returned; net revenue =
 *   netRevenueSql(), refunds counted once), each tagged with the buyer who held the ad's account on the order's Cairo day;
 * - inbox conversations from ads, tagged the same way on the day of the ad touch.
 */
final class AdsQuery
{
    /**
     * A3 (F-043): statuses that are not real revenue: unpaid (awaiting payment) or never a real Shopify order.
     * Used by orders(), the conversations() `ordered` subquery and the store side of RevenueSummary.
     */
    public const NOT_REAL_STATUSES = [OrderStatus::AwaitingPayment->value, OrderStatus::Cancelled->value, OrderStatus::Failed->value];

    /** Alias of NOT_REAL_STATUSES, kept for callers. */
    public const DEAD_ORDER_STATUSES = self::NOT_REAL_STATUSES;

    /** Courier-returned COD orders (spec section 2: real orders are net of returns); usually no Shopify refund. */
    public const RETURNED_SHIPMENT = ShipmentStatus::Returned->value;

    public const SUMS = 'COALESCE(SUM(m.spend), 0) as spend, COALESCE(SUM(m.purchase_value), 0) as purchase_value, '
        .'COALESCE(SUM(m.purchases), 0) as purchases, COALESCE(SUM(m.impressions), 0) as impressions, '
        .'COALESCE(SUM(m.clicks), 0) as clicks, COALESCE(SUM(m.reach), 0) as reach';

    /** Read once per report: withTax() runs for every row, card and day of a page. */
    private ?float $taxRate = null;

    public function __construct(private readonly BuyerResolver $resolver, private readonly AdsSettings $settings) {}

    /** ad_daily_metrics m with buyer_id + joins ads (ad) and ad_accounts (acc), filtered by AdsFilter. */
    public function metrics(AdsFilter $f): Builder
    {
        $q = $this->resolver->metricsWithBuyer($f->from, $f->to)
            ->join('ads as ad', 'ad.id', '=', 'm.ad_id')
            ->join('ad_accounts as acc', 'acc.id', '=', 'm.ad_account_id');

        if ($f->isEmpty()) {
            return $q->whereRaw('1 = 0');
        }
        if ($f->activeCampaignsOnly) {
            $q->join('ad_campaigns as actv', 'actv.id', '=', 'ad.ad_campaign_id')
                ->whereIn('actv.status', AdWriteService::ACTIVE_STATUSES);
        }
        if ($f->platform !== null) {
            $q->where('acc.platform', $f->platform);
        }
        if ($f->accountIds !== null) {
            $q->whereIn('m.ad_account_id', $f->accountIds);
        }
        if ($f->buyerId !== null) {
            $q->where('a.media_buyer_id', $f->buyerId);
        }
        if ($f->restrictBuyerId !== null) {
            $q->where('a.media_buyer_id', $f->restrictBuyerId);
        }

        return $q;
    }

    /**
     * Metric sums, optionally grouped. $groups maps an output alias to a column expression.
     *
     * @param  array<string, string>  $groups
     * @return Collection<int, object>
     */
    public function sums(AdsFilter $f, array $groups = [], ?callable $tap = null): Collection
    {
        $q = $this->metrics($f)->select([]);
        foreach ($groups as $alias => $expr) {
            $q->selectRaw("{$expr} as {$alias}")->groupBy(DB::raw($expr));
        }
        $q->selectRaw(self::SUMS);
        if ($tap !== null) {
            $tap($q);
        }

        return $q->get();
    }

    /** @var array<string, array{control: Collection, ads: Collection}> memo per filter, one request */
    private array $controlMemo = [];

    /**
     * Control rows (ad_account_daily: what the platform reports for the account whatever the ads' status) and the
     * ad-level sums per (account, day), over the filter's accounts, platform and buyer-of-the-day. Memoised per filter.
     *
     * @return array{control: Collection<string, object>, ads: Collection<string, object>}
     */
    private function controlAndAds(AdsFilter $f): array
    {
        $f = $f->allSpend();
        $key = serialize([$f->fromDate(), $f->toDate(), $f->platform, $f->buyerId, $f->accountIds, $f->restrictBuyerId]);
        if (isset($this->controlMemo[$key])) {
            return $this->controlMemo[$key];
        }
        if ($f->isEmpty()) {
            return $this->controlMemo[$key] = ['control' => collect(), 'ads' => collect()];
        }

        $q = DB::table('ad_account_daily as d')
            ->leftJoin('ad_account_assignments as a', function ($j) {
                $j->on('a.ad_account_id', '=', 'd.ad_account_id')
                    ->whereColumn('d.date', '>=', 'a.starts_on')
                    ->where(fn ($w) => $w->whereNull('a.ends_on')->orWhereColumn('d.date', '<=', 'a.ends_on'));
            })
            ->join('ad_accounts as acc', 'acc.id', '=', 'd.ad_account_id')
            ->whereBetween('d.date', [$f->fromDate(), $f->toDate()]);
        if ($f->platform !== null) {
            $q->where('acc.platform', $f->platform);
        }
        if ($f->accountIds !== null) {
            $q->whereIn('d.ad_account_id', $f->accountIds);
        }
        foreach (array_filter([$f->buyerId, $f->restrictBuyerId], fn ($b) => $b !== null) as $buyer) {
            $q->where('a.media_buyer_id', $buyer);
        }
        $control = $q->get(['d.ad_account_id', 'd.date', 'd.spend', 'd.purchases', 'd.purchase_value', 'd.impressions'])
            ->keyBy(fn ($r) => $r->ad_account_id.'|'.substr((string) $r->date, 0, 10));

        $ads = $this->metrics($f)->select(['m.ad_account_id', 'm.date'])
            ->selectRaw('COALESCE(SUM(m.spend), 0) as spend, COALESCE(SUM(m.purchases), 0) as purchases, COALESCE(SUM(m.purchase_value), 0) as purchase_value')
            ->groupBy('m.ad_account_id', 'm.date')->get()
            ->keyBy(fn ($r) => $r->ad_account_id.'|'.substr((string) $r->date, 0, 10));

        return $this->controlMemo[$key] = ['control' => $control, 'ads' => $ads];
    }

    /**
     * Blend of the control and the ad rows per (account, day): the control row where there is one, the ad-level sum
     * where it is missing. `source` = 'account' (every day with ad rows has a control row) or 'mixed'; null when no
     * control row exists at all (source 'ads'). `itemised_gap` = control - sum of ads over the covered days only.
     *
     * @param  Collection<string, object>  $control
     * @param  Collection<string, object>  $ads
     * @return object{spend:float, purchases:float, purchase_value:float, itemised_gap:float, source:string}|null
     */
    private function blend(Collection $control, Collection $ads): ?object
    {
        if ($control->isEmpty()) {
            return null;
        }

        $spend = $purchases = $value = $gap = 0.0;
        $uncovered = false;
        foreach ($control as $k => $c) {
            $spend += (float) $c->spend;
            $purchases += (float) $c->purchases;
            $value += (float) $c->purchase_value;
            $gap += (float) $c->spend - (float) ($ads[$k]->spend ?? 0);
        }
        foreach ($ads as $k => $a) {
            if (! $control->has($k)) {
                $uncovered = true;
                $spend += (float) $a->spend;
                $purchases += (float) $a->purchases;
                $value += (float) $a->purchase_value;
            }
        }

        return (object) [
            'spend' => $spend, 'purchases' => $purchases, 'purchase_value' => $value,
            'itemised_gap' => round($gap, 2), 'source' => $uncovered ? 'mixed' : 'account',
        ];
    }

    /** Blended account-level totals for the whole filter; null when no control row exists in it. */
    public function accountTotals(AdsFilter $f): ?object
    {
        ['control' => $control, 'ads' => $ads] = $this->controlAndAds($f);

        return $this->blend($control, $ads);
    }

    /**
     * The same blend per account, from the one memoised read.
     *
     * @return array<int, object>
     */
    public function accountTotalsByAccount(AdsFilter $f): array
    {
        ['control' => $control, 'ads' => $ads] = $this->controlAndAds($f);
        $out = [];
        $adsByAccount = $ads->groupBy('ad_account_id');
        foreach ($control->groupBy('ad_account_id') as $accountId => $rows) {
            $key = fn ($r) => $r->ad_account_id.'|'.substr((string) $r->date, 0, 10);
            $blend = $this->blend($rows->keyBy($key), ($adsByAccount[$accountId] ?? collect())->keyBy($key));
            if ($blend !== null) {
                $out[(int) $accountId] = $blend;
            }
        }

        return $out;
    }

    /**
     * derive() of the ad-level sums, with spend, purchase value, purchases, ROAS and CPA from the blended account
     * totals when any control row exists. CPM, CPC and CTR keep the ad-level sums (the control has no clicks).
     * `source` is 'account', 'mixed' or 'ads'; `itemised_gap` = control - sum of ads over the covered days (null on
     * 'ads'); `gap_state`: 'none' (within crm.ads.control_tolerance_pct of spend), 'unitemised' (control above ads) or
     * 'updating' (control below ads: the platform is still settling today's numbers).
     *
     * @return array<string, mixed>
     */
    public function deriveWithControl(AdsFilter $f, object|array $adSums): array
    {
        return $this->applyBlend($this->derive($adSums), $this->accountTotals($f));
    }

    /**
     * @param  array<string, mixed>  $d  derive() output
     * @return array<string, mixed>
     */
    public function applyBlend(array $d, ?object $blend): array
    {
        if ($blend === null) {
            return $d + ['source' => 'ads', 'itemised_gap' => null, 'gap_state' => 'none'];
        }

        $c = $this->derive([
            'spend' => $blend->spend, 'purchase_value' => $blend->purchase_value, 'purchases' => $blend->purchases,
            'impressions' => $d['impressions'], 'clicks' => $d['clicks'], 'reach' => $d['reach'],
        ]);
        $tolerance = abs($blend->spend) * (float) config('crm.ads.control_tolerance_pct', 0.5) / 100;
        $state = abs($blend->itemised_gap) <= $tolerance ? 'none' : ($blend->itemised_gap > 0 ? 'unitemised' : 'updating');

        return array_merge($d, array_intersect_key($c, array_flip(['spend', 'spend_tax', 'purchase_value', 'roas', 'purchases', 'cpa'])))
            + ['source' => $blend->source, 'itemised_gap' => $blend->itemised_gap, 'gap_state' => $state];
    }

    /**
     * Real orders in range: `ad_id` on an ad of the filtered accounts, or (no ad) `ad_campaign_id` on a
     * filtered campaign; realOrders() (not awaiting payment/cancelled/failed, shipment not returned); net = netRevenueSql(). Buyer = holder of the account on
     * the order's Cairo day; buyer filters apply on that.
     *
     * @return Collection<int, array{id:int, ad_id:?int, account_id:int, platform:string, buyer_id:?int, date:string, net:float}>
     */
    public function orders(AdsFilter $f): Collection
    {
        if ($f->isEmpty()) {
            return collect();
        }

        $q = self::realOrders(DB::table('orders as o'))
            ->leftJoin('ads as ad', 'ad.id', '=', 'o.ad_id')
            ->leftJoin('ad_campaigns as camp', 'camp.id', '=', 'o.ad_campaign_id')
            ->join('ad_accounts as acc', 'acc.id', '=', DB::raw('COALESCE(ad.ad_account_id, camp.ad_account_id)'))
            ->where(fn ($w) => $w->whereNotNull('o.ad_id')->orWhereNotNull('o.ad_campaign_id'))
            ->whereBetween('o.placed_at', [$f->startUtc(), $f->endUtc()])
            ->select(['o.id', 'o.ad_id', 'o.placed_at', 'acc.id as account_id', 'acc.platform'])
            ->selectRaw(self::netRevenueSql().' as net');
        if ($f->activeCampaignsOnly) {
            $q->join('ad_campaigns as actv', 'actv.id', '=', DB::raw('COALESCE(ad.ad_campaign_id, o.ad_campaign_id)'))
                ->whereIn('actv.status', AdWriteService::ACTIVE_STATUSES);
        }
        $this->accountFilters($q, $f);

        $rows = $q->get();
        $owners = $this->owners($rows->pluck('account_id')->all());

        return $rows->map(function (object $r) use ($owners) {
            $date = $this->cairoDate($r->placed_at);

            return [
                'id' => (int) $r->id,
                'ad_id' => $r->ad_id !== null ? (int) $r->ad_id : null,
                'account_id' => (int) $r->account_id,
                'platform' => (string) $r->platform,
                'buyer_id' => $this->ownerOn($owners, (int) $r->account_id, $date),
                'date' => $date,
                'net' => max(0.0, round((float) $r->net, 2)),
            ];
        })->filter(fn (array $o) => $this->buyerMatches($f, $o['buyer_id']))->values();
    }

    /**
     * Conversations whose first ad (conversations.ad_id = ads.external_id) is on a filtered account,
     * ad touch in range. `ordered` = the customer placed a real (NOT_REAL_STATUSES excluded, not returned) order after the
     * touch and by the end of the range.
     *
     * @return Collection<int, array{id:int, customer_id:?int, account_id:int, buyer_id:?int, date:string, ordered:bool}>
     */
    public function conversations(AdsFilter $f): Collection
    {
        if ($f->isEmpty()) {
            return collect();
        }

        $end = $f->endUtc();
        $q = DB::table('conversations as c')
            ->join('ads as ad', 'ad.external_id', '=', 'c.ad_id')
            ->join('ad_accounts as acc', 'acc.id', '=', 'ad.ad_account_id')
            ->whereNotNull('c.ad_id')
            ->whereBetween('c.ad_attributed_at', [$f->startUtc(), $end])
            ->select(['c.id', 'c.customer_id', 'c.ad_attributed_at', 'acc.id as account_id'])
            ->selectRaw('CASE WHEN EXISTS ('
                .'SELECT 1 FROM orders o WHERE o.customer_id = c.customer_id AND o.status NOT IN (?, ?, ?) '
                .'AND (o.shipment_status IS NULL OR o.shipment_status <> ?) '
                .'AND o.placed_at > c.ad_attributed_at AND o.placed_at <= ?) THEN 1 ELSE 0 END as ordered',
                [...self::NOT_REAL_STATUSES, self::RETURNED_SHIPMENT, $end->format('Y-m-d H:i:s')])
            ->orderBy('c.id')->orderBy('ad.id');
        $this->accountFilters($q, $f);

        $rows = $q->get()->unique('id'); // an external id shared by two accounts' ads: first ad wins
        $owners = $this->owners($rows->pluck('account_id')->all());

        return $rows->map(function (object $r) use ($owners) {
            $date = $this->cairoDate($r->ad_attributed_at);

            return [
                'id' => (int) $r->id,
                'customer_id' => $r->customer_id !== null ? (int) $r->customer_id : null,
                'account_id' => (int) $r->account_id,
                'buyer_id' => $this->ownerOn($owners, (int) $r->account_id, $date),
                'date' => $date,
                'ordered' => (bool) $r->ordered,
            ];
        })->filter(fn (array $c) => $this->buyerMatches($f, $c['buyer_id']))->values();
    }

    /**
     * The real-order predicate on `orders as o` (A3): status not in NOT_REAL_STATUSES and the shipment not
     * courier-returned. Shared by the CRM side (orders()) and the store side (RevenueSummary).
     */
    public static function realOrders(Builder $q): Builder
    {
        return $q->whereNotIn('o.status', self::NOT_REAL_STATUSES)
            ->where(fn ($w) => $w->whereNull('o.shipment_status')->orWhere('o.shipment_status', '!=', self::RETURNED_SHIPMENT));
    }

    /**
     * Net revenue of one order `o`, refunds counted once (A3, F-005), never below 0:
     * - a store order's `total` is Shopify current_total_price (OrderMapper.php:410), already after refunds: used as is;
     * - a chat order's `total` is set by the CRM and never refreshed from Shopify (OrderMapper::updateChatOrder), so its
     *   Shopify refunds (refunds table) are subtracted here.
     */
    public static function netRevenueSql(): string
    {
        $chat = OrderSource::Chat->value;
        $chatNet = 'o.total - COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.order_id = o.id), 0)';

        return "(CASE WHEN o.source = '{$chat}' THEN (CASE WHEN {$chatNet} > 0 THEN {$chatNet} ELSE 0 END) "
            .'ELSE (CASE WHEN o.total > 0 THEN o.total ELSE 0 END) END)';
    }

    /**
     * @param  Collection<int, array{customer_id:?int, ordered:bool}>  $conversations
     * @return array{conversations:int, conversations_ordered:int}
     */
    public static function conversationCounts(Collection $conversations): array
    {
        return [
            'conversations' => $conversations->count(),
            'conversations_ordered' => $conversations->where('ordered', true)->pluck('customer_id')->filter()->unique()->count(),
        ];
    }

    /**
     * The usual derived figures from raw sums. Ratios are null when their denominator is 0.
     *
     * @return array{spend:float, spend_tax:float, purchase_value:float, roas:?float, purchases:float, cpa:?float, impressions:int, clicks:int, ctr:?float, reach:int, cpm:?float, cpc:?float}
     */
    public function derive(object|array $s): array
    {
        $s = (object) $s;
        $spend = (float) ($s->spend ?? 0);
        $value = (float) ($s->purchase_value ?? 0);
        $purchases = (float) ($s->purchases ?? 0);
        $impr = (int) ($s->impressions ?? 0);
        $clicks = (int) ($s->clicks ?? 0);

        return [
            'spend' => round($spend, 2),
            'spend_tax' => $this->withTax($spend),
            'purchase_value' => round($value, 2),
            'roas' => self::ratio($value, $spend, 2),
            'purchases' => round($purchases, 2),
            'cpa' => self::ratio($spend, $purchases, 2),
            'impressions' => $impr,
            'clicks' => $clicks,
            'ctr' => self::ratio($clicks, $impr, 4),
            'reach' => (int) ($s->reach ?? 0),
            'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
            'cpc' => self::ratio($spend, $clicks, 2),
        ];
    }

    public function withTax(float $spend): float
    {
        $this->taxRate ??= $this->settings->taxRate();

        return round($spend * (1 + $this->taxRate), 2);
    }

    public static function ratio(float|int $num, float|int $den, int $precision): ?float
    {
        return (float) $den == 0.0 ? null : round($num / $den, $precision);
    }

    /**
     * Assignment periods per account.
     *
     * @param  list<int>  $accountIds
     * @return array<int, list<array{0:string, 1:?string, 2:int}>> account => [starts_on, ends_on, buyer]
     */
    public function owners(array $accountIds): array
    {
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
        if ($accountIds === []) {
            return [];
        }

        $out = [];
        foreach (AdAccountAssignment::query()->whereIn('ad_account_id', $accountIds)->toBase()->get(['ad_account_id', 'media_buyer_id', 'starts_on', 'ends_on']) as $a) {
            $out[(int) $a->ad_account_id][] = [substr((string) $a->starts_on, 0, 10), $a->ends_on !== null ? substr((string) $a->ends_on, 0, 10) : null, (int) $a->media_buyer_id];
        }

        return $out;
    }

    /** @param  array<int, list<array{0:string, 1:?string, 2:int}>>  $owners */
    public function ownerOn(array $owners, int $accountId, string $date): ?int
    {
        foreach ($owners[$accountId] ?? [] as [$start, $end, $buyer]) {
            if ($start <= $date && ($end === null || $end >= $date)) {
                return $buyer;
            }
        }

        return null;
    }

    public function cairoDate(mixed $utc): string
    {
        return CarbonImmutable::parse((string) $utc, 'UTC')->setTimezone(AdsFilter::TIMEZONE)->toDateString();
    }

    private function buyerMatches(AdsFilter $f, ?int $buyer): bool
    {
        return ($f->buyerId === null || $buyer === $f->buyerId)
            && ($f->restrictBuyerId === null || $buyer === $f->restrictBuyerId);
    }

    private function accountFilters(Builder $q, AdsFilter $f): void
    {
        if ($f->platform !== null) {
            $q->where('acc.platform', $f->platform);
        }
        if ($f->accountIds !== null) {
            $q->whereIn('acc.id', $f->accountIds);
        }
    }
}
