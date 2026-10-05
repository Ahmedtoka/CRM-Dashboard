<?php

namespace App\Ads\Reports;

use App\Models\BuyerTarget;
use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One card per media buyer: spend of the metric rows they held on each day, real orders and inbox
 * conversations attributed to them on the day, monthly budget prorated over the range, target ROAS
 * of the month of `to`. Rows without a holder form the «unassigned» card, always last.
 */
final class BuyerScorecard
{
    public function __construct(
        private readonly AdsQuery $q,
        private readonly AdsOverview $overview,
        private readonly RunningCreatives $creatives,
    ) {}

    /** @return list<array> sorted by spend desc, unassigned last */
    public function build(AdsFilter $f): array
    {
        if ($f->isEmpty()) {
            return [];
        }

        // Cards are totals: every campaign's spend counts (D1). Lists below (campaigns, top ads) stay scoped.
        $f = $f->allSpend();
        $sums = $this->q->sums($f, ['buyer_id' => 'a.media_buyer_id'])->keyBy(fn ($r) => $this->key($r->buyer_id));
        $orders = $this->q->orders($f)->groupBy(fn ($o) => $this->key($o['buyer_id']));
        $convs = $this->q->conversations($f)->groupBy(fn ($c) => $this->key($c['buyer_id']));
        $accounts = $this->accounts($f);
        $currencies = $this->currencies($f);

        $ids = collect([...$sums->keys(), ...$orders->keys(), ...$convs->keys()])->unique()->filter(fn ($k) => $k !== 0)->all();
        $buyers = MediaBuyer::query()
            ->where(fn ($w) => $w->whereIn('id', $ids)->orWhere(fn ($a) => $this->activeCandidates($a, $f)))
            ->when($f->buyerId !== null, fn ($w) => $w->where('id', $f->buyerId))
            ->when($f->restrictBuyerId !== null, fn ($w) => $w->where('id', $f->restrictBuyerId))
            ->get(['id', 'name', 'color']);
        $targets = $this->targets($buyers->pluck('id')->all(), $f);

        $cards = $buyers->map(fn (MediaBuyer $b) => $this->card(
            (int) $b->id, (string) $b->name, $b->color, $accounts[$b->id] ?? [],
            $sums[$b->id] ?? null, $orders[$b->id] ?? collect(), $convs[$b->id] ?? collect(), $targets[$b->id] ?? null, $currencies[$b->id] ?? [],
        ))->sortByDesc('spend')->values();

        $hasUnassigned = $sums->has(0) || $orders->has(0) || $convs->has(0);
        if ($hasUnassigned && $f->buyerId === null && $f->restrictBuyerId === null) {
            $cards->push($this->card(null, __('ads.unassigned'), null, $accounts[0] ?? [], $sums[0] ?? null, $orders[0] ?? collect(), $convs[0] ?? collect(), null, $currencies[0] ?? []));
        }

        return $cards->all();
    }

    /** Same keys for one buyer + daily series, the accounts held in range, top 10 ads and campaigns. */
    public function detail(MediaBuyer $b, AdsFilter $f): array
    {
        if ($f->restrictBuyerId !== null && $f->restrictBuyerId !== $b->id) {
            $f = $f->with(['restrictBuyerId' => 0]);
        }
        $bf = $f->with(['buyerId' => $b->id]);
        $card = collect($this->build($bf))->firstWhere('buyer_id', $b->id)
            ?? $this->card((int) $b->id, (string) $b->name, $b->color, [], null, collect(), collect(), null, []);
        $orders = $this->q->orders($bf->allSpend());

        return $card + [
            // a money series: with mixed currencies it would add one to another
            'daily' => ($card['mixed_currencies'] ?? false) ? [] : $this->overview->daily($bf, $orders),
            'assignments' => $this->assignments($b, $bf),
            'top_ads' => $this->creatives->build($bf, ['sort' => 'spend', 'per_page' => 10])['data'],
            'campaigns' => $this->campaigns($bf, $orders),
        ];
    }

    private function card(?int $id, string $name, ?string $color, array $accounts, ?object $sums, Collection $orders, Collection $convs, ?array $target, array $currencies = []): array
    {
        $d = $this->q->derive($sums ?? []);
        $revenue = round((float) $orders->sum('net'), 2);
        $budget = $target['budget'] ?? null;
        $targetRoas = $target['target_roas'] ?? null;
        // From the raw sums, not the rounded ROAS: value / (spend x target).
        $rawSpend = (float) ($sums->spend ?? 0);
        $rawValue = (float) ($sums->purchase_value ?? 0);

        $mixed = count($currencies) > 1;
        $foreign = ! $mixed && $currencies !== [] && $currencies[0] !== 'EGP';

        $card = [
            'buyer_id' => $id,
            'name' => $name,
            'color' => $color,
            'accounts' => $accounts,
            'spend' => $d['spend'],
            'spend_tax' => $d['spend_tax'],
            'purchase_value' => $d['purchase_value'],
            'roas' => $d['roas'],
            'purchases' => $d['purchases'],
            'cpa' => $d['cpa'],
            'ctr' => $d['ctr'],
            'real_orders' => $orders->count(),
            'real_revenue' => $revenue,
            'real_roas' => AdsQuery::ratio($revenue, $d['spend'], 2),
        ] + AdsQuery::conversationCounts($convs) + [
            'budget' => $budget,
            'budget_used_pct' => $budget !== null ? AdsQuery::ratio($d['spend'] * 100, $budget, 2) : null,
            'target_roas' => $targetRoas,
            'roas_vs_target' => $targetRoas !== null && $rawSpend > 0 ? AdsQuery::ratio($rawValue, $rawSpend * (float) $targetRoas, 2) : null,
            'mixed_currencies' => $mixed,
        ];
        if ($foreign) {
            $card['real_roas'] = null; // order revenue is EGP, the spend is not
        }
        if ($mixed) {
            // A9: one buyer holding accounts in several currencies: no figure may add them
            foreach (['spend', 'spend_tax', 'purchase_value', 'roas', 'cpa', 'real_revenue', 'real_roas', 'budget_used_pct', 'roas_vs_target'] as $k) {
                $card[$k] = null;
            }
        }

        return $card;
    }

    /**
     * buyer id (0 = unassigned) => the currencies of the accounts whose rows (ad or control, buyer of the day) count here.
     *
     * @return array<int, list<string>>
     */
    private function currencies(AdsFilter $f): array
    {
        $out = [];
        foreach ($this->q->metrics($f)->select(['a.media_buyer_id as buyer_id', 'acc.currency'])->distinct()->get() as $r) {
            if ($r->currency !== null && $r->currency !== '') {
                $out[$this->key($r->buyer_id)][strtoupper((string) $r->currency)] = true;
            }
        }

        return array_map(fn (array $c) => array_keys($c), $out);
    }

    /** buyer id (0 = unassigned) => accounts held in range (assignment periods, else metric rows). */
    private function accounts(AdsFilter $f): array
    {
        $held = DB::table('ad_account_assignments as a')
            ->join('ad_accounts as acc', 'acc.id', '=', 'a.ad_account_id')
            ->where('a.starts_on', '<=', $f->toDate())
            ->where(fn ($w) => $w->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $f->fromDate()))
            ->when($f->platform !== null, fn ($w) => $w->where('acc.platform', $f->platform))
            ->when($f->accountIds !== null, fn ($w) => $w->whereIn('acc.id', $f->accountIds))
            ->distinct()->orderBy('acc.name')->orderBy('acc.id')
            ->get(['a.media_buyer_id as buyer_id', 'acc.id', 'acc.name', 'acc.platform']);
        $unassigned = $this->q->metrics($f)->whereNull('a.media_buyer_id')->select(['acc.id', 'acc.name', 'acc.platform'])->distinct()
            ->orderBy('acc.name')->orderBy('acc.id')->get()->each(fn ($r) => $r->buyer_id = 0);

        $out = [];
        foreach ($held->concat($unassigned) as $r) {
            $out[(int) $r->buyer_id][(int) $r->id] = ['id' => (int) $r->id, 'name' => (string) $r->name, 'platform' => (string) $r->platform];
        }

        return array_map('array_values', $out);
    }

    /** Active buyers are listed even without data, unless an account/platform filter excludes them. */
    private function activeCandidates($q, AdsFilter $f): void
    {
        $q->where('is_active', true);
        if ($f->platform === null && $f->accountIds === null) {
            return;
        }
        $q->whereExists(fn ($e) => $e->from('ad_account_assignments as a')
            ->join('ad_accounts as acc', 'acc.id', '=', 'a.ad_account_id')
            ->whereColumn('a.media_buyer_id', 'media_buyers.id')
            ->where('a.starts_on', '<=', $f->toDate())
            ->where(fn ($w) => $w->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $f->fromDate()))
            ->when($f->platform !== null, fn ($w) => $w->where('acc.platform', $f->platform))
            ->when($f->accountIds !== null, fn ($w) => $w->whereIn('acc.id', $f->accountIds)));
    }

    /**
     * Budget = Σ month budget × covered days ÷ days in month (null when no month has a target);
     * target ROAS = the target of the month of `to`.
     *
     * @param  list<int>  $buyerIds
     * @return array<int, array{budget:?float, target_roas:?float}>
     */
    private function targets(array $buyerIds, AdsFilter $f): array
    {
        if ($buyerIds === []) {
            return [];
        }

        $firstMonth = $f->from->startOfMonth();
        $lastMonth = $f->to->startOfMonth();
        $rows = BuyerTarget::query()->whereIn('media_buyer_id', $buyerIds)
            ->whereBetween('month', [$firstMonth->toDateString(), $lastMonth->toDateString()])
            ->toBase()->get(['media_buyer_id', 'month', 'budget', 'target_roas']);

        $out = [];
        foreach ($rows as $t) {
            $month = CarbonImmutable::parse(substr((string) $t->month, 0, 10), AdsFilter::TIMEZONE)->startOfMonth();
            $start = $month->greaterThan($f->from) ? $month : $f->from;
            $end = $month->endOfMonth()->startOfDay();
            $end = $end->lessThan($f->to) ? $end : $f->to;
            $covered = (int) $start->diffInDays($end) + 1;
            $share = (float) $t->budget * $covered / $month->daysInMonth;

            $id = (int) $t->media_buyer_id;
            $out[$id]['budget'] = round(($out[$id]['budget'] ?? 0.0) + $share, 2);
            $out[$id]['target_roas'] ??= null;
            if ($month->equalTo($lastMonth) && $t->target_roas !== null) {
                $out[$id]['target_roas'] = round((float) $t->target_roas, 2);
            }
        }

        return $out;
    }

    /** @return list<array{account_id:int, account:string, platform:string, starts_on:string, ends_on:?string}> */
    private function assignments(MediaBuyer $b, AdsFilter $f): array
    {
        return DB::table('ad_account_assignments as a')
            ->join('ad_accounts as acc', 'acc.id', '=', 'a.ad_account_id')
            ->where('a.media_buyer_id', $b->id)
            ->where('a.starts_on', '<=', $f->toDate())
            ->where(fn ($w) => $w->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $f->fromDate()))
            ->when($f->platform !== null, fn ($w) => $w->where('acc.platform', $f->platform))
            ->when($f->accountIds !== null, fn ($w) => $w->whereIn('acc.id', $f->accountIds))
            ->orderBy('a.starts_on')
            ->get(['acc.id', 'acc.name', 'acc.platform', 'a.starts_on', 'a.ends_on'])
            ->map(fn ($r) => [
                'account_id' => (int) $r->id, 'account' => (string) $r->name, 'platform' => (string) $r->platform,
                'starts_on' => substr((string) $r->starts_on, 0, 10), 'ends_on' => $r->ends_on !== null ? substr((string) $r->ends_on, 0, 10) : null,
            ])->all();
    }

    /**
     * @param  Collection<int, array{id:int, ad_id:?int}>  $orders
     * @return list<array>
     */
    private function campaigns(AdsFilter $f, Collection $orders): array
    {
        $rows = $this->q->sums($f, ['campaign_id' => 'ad.ad_campaign_id'], fn ($b) => $b->orderByDesc('spend'));
        $ids = $rows->pluck('campaign_id')->filter()->all();
        $info = DB::table('ad_campaigns as camp')->join('ad_accounts as acc', 'acc.id', '=', 'camp.ad_account_id')
            ->whereIn('camp.id', $ids)->get(['camp.id', 'camp.name', 'camp.status', 'acc.name as account', 'acc.platform'])->keyBy('id');

        // real orders per campaign: by the order's ad, or the campaign it was attributed to
        $orderCampaigns = DB::table('orders')->whereIn('id', $orders->pluck('id')->all())->pluck('ad_campaign_id', 'id');
        $adCampaigns = DB::table('ads')->whereIn('id', $orders->pluck('ad_id')->filter()->unique()->all())->pluck('ad_campaign_id', 'id');
        $real = $orders->groupBy(fn ($o) => (int) ($o['ad_id'] !== null ? ($adCampaigns[$o['ad_id']] ?? 0) : ($orderCampaigns[$o['id']] ?? 0)));

        return $rows->map(function (object $r) use ($info, $real) {
            $d = $this->q->derive($r);
            $c = $r->campaign_id !== null ? ($info[(int) $r->campaign_id] ?? null) : null;
            $o = $real[(int) ($r->campaign_id ?? 0)] ?? collect();

            return [
                'id' => $r->campaign_id !== null ? (int) $r->campaign_id : null,
                'name' => $c->name ?? null,
                'status' => $c->status ?? null,
                'account' => $c->account ?? null,
                'platform' => $c->platform ?? null,
                'spend' => $d['spend'], 'spend_tax' => $d['spend_tax'], 'purchase_value' => $d['purchase_value'],
                'roas' => $d['roas'], 'purchases' => $d['purchases'], 'cpa' => $d['cpa'], 'ctr' => $d['ctr'],
                'real_orders' => $o->count(), 'real_revenue' => round((float) $o->sum('net'), 2),
            ];
        })->values()->all();
    }

    private function key(mixed $buyerId): int
    {
        return $buyerId === null ? 0 : (int) $buyerId;
    }
}
