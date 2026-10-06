<?php

namespace App\Ads\Alerts;

use App\Ads\Control\AdWriteService;
use App\Ads\Materials\MaterialService;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdsSyncRun;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every read the decision rules make (DB only, no platform call). Days are Cairo calendar days, inclusive; metric dates
 * are compared as strings with an end-of-day upper bound so `Y-m-d` and `Y-m-d 00:00:00` storage both match.
 */
final class AlertData
{
    /** @return array{spend: float, purchases: float, purchase_value: float, msg_conversations: int, days_live: int, first_day: ?string} */
    public static function emptySums(): array
    {
        return ['spend' => 0.0, 'purchases' => 0.0, 'purchase_value' => 0.0, 'msg_conversations' => 0, 'days_live' => 0, 'first_day' => null];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function utcRange(string $from, string $to): array
    {
        return [
            CarbonImmutable::parse($from, AdsFilter::TIMEZONE)->startOfDay()->utc(),
            CarbonImmutable::parse($to, AdsFilter::TIMEZONE)->endOfDay()->utc(),
        ];
    }

    /** @return Collection<int, Ad> ads whose own status is active and whose effective status is not a paused parent */
    public function liveAds(int $accountId): Collection
    {
        return Ad::query()->with(['campaign:id,objective,status', 'adSet:id,status'])
            ->where('ad_account_id', $accountId)
            ->whereIn('status', AdWriteService::ACTIVE_STATUSES)
            ->where(fn ($w) => $w->whereNull('effective_status')->orWhereIn('effective_status', AdWriteService::ACTIVE_STATUSES))
            ->orderBy('id')->get()->keyBy('id');
    }

    /** @return Collection<int, Ad> */
    public function accountAds(int $accountId): Collection
    {
        return Ad::query()->with('campaign:id,objective')->where('ad_account_id', $accountId)->orderBy('id')->get()->keyBy('id');
    }

    /**
     * @param  list<int>  $adIds
     * @return array<int, array{spend: float, purchases: float, purchase_value: float, msg_conversations: int, days_live: int, first_day: ?string}>
     */
    public function sums(array $adIds, string $from, string $to): array
    {
        if ($adIds === []) {
            return [];
        }

        return DB::table('ad_daily_metrics')->whereIn('ad_id', $adIds)
            ->where('date', '>=', $from)->where('date', '<=', $to.' 23:59:59')
            ->groupBy('ad_id')
            ->selectRaw('ad_id, COALESCE(SUM(spend), 0) as spend, COALESCE(SUM(purchases), 0) as purchases, '
                .'COALESCE(SUM(purchase_value), 0) as purchase_value, COALESCE(SUM(msg_conversations), 0) as msg_conversations, '
                .'SUM(CASE WHEN spend > 0 THEN 1 ELSE 0 END) as days_live, MIN(CASE WHEN spend > 0 THEN date END) as first_day')
            ->get()
            ->mapWithKeys(fn (object $r) => [(int) $r->ad_id => [
                'spend' => round((float) $r->spend, 2), 'purchases' => (float) $r->purchases, 'purchase_value' => round((float) $r->purchase_value, 2),
                'msg_conversations' => (int) $r->msg_conversations, 'days_live' => (int) $r->days_live,
                'first_day' => $r->first_day !== null ? substr((string) $r->first_day, 0, 10) : null,
            ]])->all();
    }

    /** @return array<string, float> Cairo day => spend; the account control row wins when it is higher (unitemised spend). */
    public function dailyTotals(int $accountId, string $from, string $to): array
    {
        $read = fn (string $table) => DB::table($table)->where('ad_account_id', $accountId)
            ->where('date', '>=', $from)->where('date', '<=', $to.' 23:59:59')
            ->groupByRaw('substr(date, 1, 10)')->selectRaw('substr(date, 1, 10) as d, COALESCE(SUM(spend), 0) as s')
            ->pluck('s', 'd');
        $ads = $read('ad_daily_metrics');
        $control = $read('ad_account_daily');

        $out = [];
        foreach ($ads->keys()->merge($control->keys())->unique()->sort() as $day) {
            $out[(string) $day] = round(max((float) ($ads[$day] ?? 0), (float) ($control[$day] ?? 0)), 2);
        }

        return $out;
    }

    /**
     * @param  list<int>  $adIds
     * @return array<int, array{count: int, net: float}>
     */
    public function realOrders(array $adIds, string $from, string $to): array
    {
        if ($adIds === []) {
            return [];
        }
        [$s, $e] = self::utcRange($from, $to);

        return AdsQuery::realOrders(DB::table('orders as o'))
            ->whereIn('o.ad_id', $adIds)->whereBetween('o.placed_at', [$s, $e])
            ->groupBy('o.ad_id')
            ->selectRaw('o.ad_id, COUNT(*) as n, COALESCE(SUM('.AdsQuery::netRevenueSql().'), 0) as net')
            ->get()
            ->mapWithKeys(fn (object $r) => [(int) $r->ad_id => ['count' => (int) $r->n, 'net' => round((float) $r->net, 2)]])
            ->all();
    }

    /**
     * Orders placed in range with a final shipment: delivered, or refused/returned (BreakEven::REFUSED).
     *
     * @param  list<int>  $adIds
     * @return array<int, array{terminal: int, refused: int}>
     */
    public function shipmentOutcomes(array $adIds, string $from, string $to): array
    {
        if ($adIds === []) {
            return [];
        }
        [$s, $e] = self::utcRange($from, $to);
        $refused = "'".implode("','", BreakEven::REFUSED)."'";

        return DB::table('orders as o')->whereIn('o.ad_id', $adIds)->whereNotIn('o.status', AdsQuery::NOT_REAL_STATUSES)
            ->whereBetween('o.placed_at', [$s, $e])
            ->groupBy('o.ad_id')
            ->selectRaw("o.ad_id, SUM(CASE WHEN o.shipment_status IN ({$refused}) THEN 1 ELSE 0 END) as refused, "
                ."SUM(CASE WHEN o.shipment_status IN ({$refused}) OR o.shipment_status = 'delivered' OR o.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as terminal")
            ->get()
            ->filter(fn (object $r) => (int) $r->terminal > 0)
            ->mapWithKeys(fn (object $r) => [(int) $r->ad_id => ['terminal' => (int) $r->terminal, 'refused' => (int) $r->refused]])
            ->all();
    }

    /**
     * @param  list<int>  $adIds
     * @return array<int, list<array{material_id: int, product_id: int, stock: string, need_stop_at: ?string}>>
     */
    public function productLinks(array $adIds): array
    {
        if ($adIds === []) {
            return [];
        }

        $out = [];
        DB::table('ad_material_ads as l')
            ->join('ad_materials', 'ad_materials.id', '=', 'l.ad_material_id')
            ->whereIn('l.ad_id', $adIds)->whereNotNull('ad_materials.product_id')
            ->select(['l.ad_id', 'ad_materials.id as material_id', 'ad_materials.product_id', 'ad_materials.need_stop_at'])
            ->selectRaw('('.MaterialService::stockSql().') as stock')
            ->orderBy('ad_materials.id')->get()
            ->each(function (object $r) use (&$out) {
                $out[(int) $r->ad_id][] = [
                    'material_id' => (int) $r->material_id, 'product_id' => (int) $r->product_id, 'stock' => (string) $r->stock,
                    'need_stop_at' => $r->need_stop_at !== null ? CarbonImmutable::parse((string) $r->need_stop_at, 'UTC')->toIso8601String() : null,
                ];
            });

        return $out;
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, Product>
     */
    public function products(array $productIds): Collection
    {
        return Product::withTrashed()->with('variants')->whereIn('id', $productIds)->get()->keyBy('id');
    }

    /**
     * Best-selling variants per product by quantity on real orders in range.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<int>>
     */
    public function topVariants(array $productIds, string $from, string $to, int $limit): array
    {
        if ($productIds === []) {
            return [];
        }
        [$s, $e] = self::utcRange($from, $to);

        $out = [];
        DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->whereIn('v.product_id', $productIds)->whereNotIn('o.status', AdsQuery::NOT_REAL_STATUSES)
            ->whereBetween('o.placed_at', [$s, $e])
            ->groupBy('v.product_id', 'i.variant_id')
            ->selectRaw('v.product_id, i.variant_id, SUM(i.qty) as qty')
            ->orderByDesc('qty')->orderBy('i.variant_id')->get()
            ->each(function (object $r) use (&$out, $limit) {
                $pid = (int) $r->product_id;
                if (count($out[$pid] ?? []) < $limit) {
                    $out[$pid][] = (int) $r->variant_id;
                }
            });

        return $out;
    }

    /**
     * @param  list<int>  $adSetIds
     * @return array<int, float>
     */
    public function adSetSpend(array $adSetIds, string $from, string $to): array
    {
        if ($adSetIds === []) {
            return [];
        }

        return DB::table('ad_daily_metrics as m')->join('ads as ad', 'ad.id', '=', 'm.ad_id')
            ->whereIn('ad.ad_set_id', $adSetIds)
            ->where('m.date', '>=', $from)->where('m.date', '<=', $to.' 23:59:59')
            ->groupBy('ad.ad_set_id')->selectRaw('ad.ad_set_id, COALESCE(SUM(m.spend), 0) as spend')
            ->get()->mapWithKeys(fn (object $r) => [(int) $r->ad_set_id => round((float) $r->spend, 2)])->all();
    }

    public function lastOkSyncAt(int $accountId): ?CarbonImmutable
    {
        $at = AdsSyncRun::query()->where('ad_account_id', $accountId)->where('status', 'ok')->max('finished_at');
        if ($at !== null) {
            return CarbonImmutable::parse((string) $at, 'UTC');
        }
        $synced = AdAccount::query()->whereKey($accountId)->value('last_synced_at');

        return $synced !== null ? CarbonImmutable::parse((string) $synced, 'UTC') : null;
    }

    /**
     * Messages cohort (R-09): conversations whose FIRST referral by the ad falls in range.
     *
     * @param  list<string>  $externalIds
     * @return array<string, int>
     */
    public function cohortChats(array $externalIds, string $from, string $to): array
    {
        if ($externalIds === []) {
            return [];
        }
        [$s, $e] = self::utcRange($from, $to);

        return DB::table('conversation_ad_referrals')->whereIn('ad_external_id', $externalIds)
            ->groupBy('ad_external_id', 'conversation_id')
            ->selectRaw('ad_external_id, conversation_id, MIN(referred_at) as first_at')
            ->havingRaw('MIN(referred_at) >= ? AND MIN(referred_at) <= ?', [$s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s')])
            ->get()->countBy(fn (object $r) => (string) $r->ad_external_id)->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Minutes from each referral in range to the first outbound reply by a user or the bot (A4); null = no reply yet.
     *
     * @param  list<string>  $externalIds
     * @return list<?float>
     */
    public function firstReplyWaits(array $externalIds, string $from, string $to): array
    {
        return array_merge([], ...array_values($this->firstReplyWaitsByAd($externalIds, $from, $to)));
    }

    /**
     * The same waits per ad external id (one query for every ad).
     *
     * @param  list<string>  $externalIds
     * @return array<string, list<?float>>
     */
    public function firstReplyWaitsByAd(array $externalIds, string $from, string $to): array
    {
        if ($externalIds === []) {
            return [];
        }
        [$s, $e] = self::utcRange($from, $to);

        $out = [];
        DB::table('conversation_ad_referrals as r')
            ->whereIn('r.ad_external_id', $externalIds)->whereBetween('r.referred_at', [$s, $e])
            ->select(['r.id', 'r.ad_external_id', 'r.referred_at'])
            ->selectSub(DB::table('messages as m')->whereColumn('m.conversation_id', 'r.conversation_id')
                ->where('m.direction', 'out')->whereIn('m.sender_type', ['user', 'bot'])
                ->whereColumn('m.created_at', '>=', 'r.referred_at')->selectRaw('MIN(m.created_at)'), 'replied_at')
            ->orderBy('r.id')->get()
            ->each(function (object $r) use (&$out) {
                $out[(string) $r->ad_external_id][] = $r->replied_at === null ? null : round(
                    CarbonImmutable::parse((string) $r->referred_at, 'UTC')->diffInSeconds(CarbonImmutable::parse((string) $r->replied_at, 'UTC'), true) / 60, 1);
            });

        return $out;
    }
}
