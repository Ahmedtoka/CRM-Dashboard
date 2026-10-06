<?php

namespace App\Inbox\Outcomes;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use App\Enums\ShipmentStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The chat funnel per ad (C 3.2, G9): محادثات → وصلت لموظفة → أوردر → اتسلم → مرتجع, and the
 * "why not bought" counts from conversation outcomes. Two grouped queries per call, never one per
 * ad. Stage definitions: the S3 plan's Definitions. Used by the ad drawer (S2), Numbers, Today (S4)
 * and the outcome rules (S5).
 */
final class ChatFunnel
{
    /**
     * @param  list<int>  $adIds
     * @return array<int, array{chats:int, to_agent:int, orders:int, delivered:int, returned:int, reasons: array<string,int>}>
     */
    public function forAds(array $adIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $adIds = array_values(array_unique(array_map('intval', $adIds)));
        if ($adIds === []) {
            return [];
        }

        $externals = DB::table('ads')->whereIn('id', $adIds)->pluck('external_id', 'id');
        $rows = $this->byExternal($externals->filter()->unique()->values()->all(), $from, $to);

        $out = [];
        foreach ($adIds as $id) {
            $ext = $externals[$id] ?? null;
            $out[$id] = $ext !== null && isset($rows[$ext]) ? $rows[$ext] : self::empty();
        }

        return $out;
    }

    /**
     * Totals over every ad of the filter's accounts, platform and buyer in its range (the Numbers page). A buyer
     * (`?buyer=N`, `buyer=me`, or a media buyer's own scope) keeps the accounts that buyer held at some point of the
     * range, from the same assignment periods as AdsQuery / BuyerScorecard.
     */
    public function forFilter(AdsFilter $f): array
    {
        if ($f->isEmpty()) {
            return self::empty();
        }

        $externals = DB::table('ads')->select('ads.external_id')
            ->join('ad_accounts as acc', 'acc.id', '=', 'ads.ad_account_id')
            ->when($f->accountIds !== null, fn ($q) => $q->whereIn('ads.ad_account_id', $f->accountIds))
            ->when($f->platform !== null, fn ($q) => $q->where('acc.platform', $f->platform));
        foreach (array_filter([$f->buyerId, $f->restrictBuyerId], fn ($b) => $b !== null) as $buyer) {
            $externals->whereExists(fn ($q) => $q->selectRaw('1')->from('ad_account_assignments as a')
                ->whereColumn('a.ad_account_id', 'ads.ad_account_id')
                ->where('a.media_buyer_id', $buyer)
                ->where('a.starts_on', '<=', $f->toDate())
                ->where(fn ($w) => $w->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $f->fromDate())));
        }

        return self::total($this->byExternal($externals, $f->startUtc(), $f->endUtc()));
    }

    /** @return array{chats:int, to_agent:int, orders:int, delivered:int, returned:int, reasons: array<string,int>} */
    public static function empty(): array
    {
        return ['chats' => 0, 'to_agent' => 0, 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'reasons' => []];
    }

    public static function total(iterable $rows): array
    {
        $sum = self::empty();
        foreach ($rows as $row) {
            foreach (['chats', 'to_agent', 'orders', 'delivered', 'returned'] as $k) {
                $sum[$k] += (int) ($row[$k] ?? 0);
            }
            foreach ($row['reasons'] ?? [] as $reason => $n) {
                $sum['reasons'][$reason] = ($sum['reasons'][$reason] ?? 0) + (int) $n;
            }
        }
        arsort($sum['reasons']);

        return $sum;
    }

    /**
     * @param  list<string>|Builder  $externals  ad external ids, or a sub-select of them
     * @return array<string, array{chats:int, to_agent:int, orders:int, delivered:int, returned:int, reasons: array<string,int>}>
     */
    private function byExternal(array|Builder $externals, CarbonInterface $from, CarbonInterface $to): array
    {
        if (is_array($externals) && $externals === []) {
            return [];
        }

        $f = CarbonImmutable::instance($from)->utc()->format('Y-m-d H:i:s');
        $t = CarbonImmutable::instance($to)->utc()->format('Y-m-d H:i:s');
        $touches = fn () => $this->touches($externals, $f, $t);

        $notReal = AdsQuery::NOT_REAL_STATUSES;
        $in = implode(', ', array_fill(0, count($notReal), '?'));
        $common = "AND COALESCE(o.placed_at, o.created_at) > t.touched_at AND COALESCE(o.placed_at, o.created_at) <= ? AND o.status NOT IN ({$in})";
        // A real order after the touch, from this conversation or the same customer: two EXISTS ORed so each
        // uses its own index (orders.conversation_id / orders.customer_id) instead of an OR inside one.
        $order = function (string $extra = '', array $extraBindings = []) use ($common, $t, $notReal): array {
            $sql = "(EXISTS (SELECT 1 FROM orders o WHERE o.conversation_id = c.id {$common} {$extra})"
                ." OR EXISTS (SELECT 1 FROM orders o WHERE c.customer_id IS NOT NULL AND o.customer_id = c.customer_id {$common} {$extra}))";
            $one = [$t, ...$notReal, ...$extraBindings];

            return [$sql, [...$one, ...$one]];
        };
        $returned = AdsQuery::RETURNED_SHIPMENT;
        [$anySql, $anyBind] = $order();
        // An order both delivered and later returned counts as returned only.
        [$deliveredSql, $deliveredBind] = $order('AND (o.delivered_at IS NOT NULL OR o.shipment_status = ?) AND (o.shipment_status IS NULL OR o.shipment_status <> ?)', [ShipmentStatus::Delivered->value, $returned]);
        [$returnedSql, $returnedBind] = $order('AND o.shipment_status = ?', [$returned]);

        $stages = DB::query()->fromSub($touches(), 't')
            ->join('conversations as c', 'c.id', '=', 't.cid')
            ->where('c.is_test', false)
            ->groupBy('t.ext')
            ->selectRaw('t.ext as ext, COUNT(*) as chats')
            ->selectRaw('SUM(CASE WHEN (c.handover_at > t.touched_at AND c.handover_at <= ?) OR EXISTS (SELECT 1 FROM queue_entries qe WHERE qe.conversation_id = c.id AND qe.enqueued_at > t.touched_at AND qe.enqueued_at <= ?) THEN 1 ELSE 0 END) as to_agent', [$t, $t])
            ->selectRaw("SUM(CASE WHEN {$anySql} THEN 1 ELSE 0 END) as orders", $anyBind)
            ->selectRaw("SUM(CASE WHEN {$deliveredSql} THEN 1 ELSE 0 END) as delivered", $deliveredBind)
            ->selectRaw("SUM(CASE WHEN {$returnedSql} THEN 1 ELSE 0 END) as returned", $returnedBind)
            ->get();

        $reasons = DB::query()->fromSub($touches(), 't')
            ->join('conversations as c', 'c.id', '=', 't.cid')
            ->join('conversation_outcomes as co', 'co.conversation_id', '=', 't.cid')
            ->where('c.is_test', false)
            ->whereColumn('co.ended_at', '>', 't.touched_at')
            ->where('co.ended_at', '<=', $t)
            ->whereIn('co.outcome', Outcome::lostValues())
            ->groupBy('t.ext', 'co.outcome')
            ->selectRaw('t.ext as ext, co.outcome as outcome, COUNT(*) as n')
            ->get()->groupBy('ext');

        $out = [];
        foreach ($stages as $s) {
            $r = [];
            foreach ($reasons->get($s->ext, collect())->sortByDesc('n') as $row) {
                $r[$row->outcome] = (int) $row->n;
            }
            $out[(string) $s->ext] = [
                'chats' => (int) $s->chats, 'to_agent' => (int) $s->to_agent, 'orders' => (int) $s->orders,
                'delivered' => (int) $s->delivered, 'returned' => (int) $s->returned, 'reasons' => $r,
            ];
        }

        return $out;
    }

    /** (ext, conversation, earliest touch in range): every referral row, plus the conversation's own ad for customers with none (pre-A4). */
    private function touches(array|Builder $externals, string $f, string $t): Builder
    {
        $referrals = DB::table('conversation_ad_referrals')->whereIn('ad_external_id', $externals)->whereBetween('referred_at', [$f, $t])
            ->selectRaw('ad_external_id as ext, conversation_id as cid, referred_at as touched_at');
        $legacy = DB::table('conversations')->whereIn('ad_id', $externals)->whereBetween('ad_attributed_at', [$f, $t])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('conversation_ad_referrals as r2')->whereColumn('r2.conversation_id', 'conversations.id'))
            ->selectRaw('ad_id as ext, id as cid, ad_attributed_at as touched_at');

        return DB::query()->fromSub($referrals->unionAll($legacy), 'u')
            ->groupBy('u.ext', 'u.cid')->selectRaw('u.ext as ext, u.cid as cid, MIN(u.touched_at) as touched_at');
    }
}
