<?php

namespace App\Ads\Reports;

use App\Ads\AdsSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AdsOverview
{
    public function __construct(private readonly AdsQuery $q, private readonly AdsSettings $settings, private readonly WinnerScorer $scorer) {}

    /** @return array{totals: array, daily: list<array>, platforms: list<array>, currency: string, tax_rate: float} */
    public function build(AdsFilter $f): array
    {
        // Totals and the daily series count every campaign (D1); only the loser share is scoped to active ones.
        $all = $f->allSpend();
        $orders = $this->q->orders($all);

        return [
            'totals' => $this->totals($all, $orders) + ['losers_spend_share' => $this->losersSpendShare($f)],
            'daily' => $this->daily($all, $orders),
            'platforms' => $this->platforms($all),
            'currency' => $this->currency($f),
            'tax_rate' => $this->settings->taxRate(),
        ];
    }

    /** @param  Collection<int, array{net:float}>|null  $orders */
    public function totals(AdsFilter $f, $orders = null): array
    {
        $f = $f->allSpend();
        $orders ??= $this->q->orders($f);
        $d = $this->q->deriveWithControl($f, $this->q->sums($f)->first() ?? []);
        $revenue = round((float) $orders->sum('net'), 2);
        // Outside-active is ad-level only (all ad rows minus active-campaign rows): it never overlaps the itemisation gap.
        $adSpend = (float) ($this->q->sums($f)->first()->spend ?? 0);
        $active = (float) ($this->q->sums($f->with(['activeCampaignsOnly' => true]))->first()->spend ?? 0);

        return $d + [
            'spend_outside_active' => round($adSpend - $active, 2),
            'real_orders' => $orders->count(),
            'real_revenue' => $revenue,
            'real_roas' => AdsQuery::ratio($revenue, $d['spend'], 2),
        ] + AdsQuery::conversationCounts($this->q->conversations($f));
    }

    /**
     * Share (0..1) of the range's spend that went to loser-tier ads (tiers scored over the Winners window); null
     * when there is no spend.
     */
    public function losersSpendShare(AdsFilter $f): ?float
    {
        $total = (float) ($this->q->sums($f)->first()->spend ?? 0);
        if ($total <= 0) {
            return null;
        }
        $ids = array_keys(array_filter($this->scorer->tiers($f), fn (string $tier) => $tier === 'loser'));
        if ($ids === []) {
            return 0.0;
        }
        $spend = (float) ($this->q->sums($f, [], fn ($b) => $b->whereIn('m.ad_id', $ids))->first()->spend ?? 0);

        return round($spend / $total, 4);
    }

    /**
     * One row per day of the range (days without data are zeros).
     *
     * @param  Collection<int, array{date:string, net:float}>|null  $orders
     * @return list<array>
     */
    public function daily(AdsFilter $f, $orders = null): array
    {
        $f = $f->allSpend();
        $orders ??= $this->q->orders($f);
        $byDate = $this->q->sums($f, ['day' => 'm.date'])->keyBy(fn ($r) => substr((string) $r->day, 0, 10));
        $ordersByDate = $orders->groupBy('date');

        return array_map(function (string $day) use ($byDate, $ordersByDate) {
            $d = $this->q->derive($byDate[$day] ?? []);
            $o = $ordersByDate[$day] ?? collect();

            return [
                'date' => $day,
                'spend' => $d['spend'], 'spend_tax' => $d['spend_tax'], 'purchase_value' => $d['purchase_value'],
                'roas' => $d['roas'], 'purchases' => $d['purchases'], 'impressions' => $d['impressions'],
                'clicks' => $d['clicks'], 'ctr' => $d['ctr'], 'cpm' => $d['cpm'], 'cpc' => $d['cpc'], 'reach' => $d['reach'],
                'real_orders' => $o->count(),
                'real_revenue' => round((float) $o->sum('net'), 2),
            ];
        }, $f->days());
    }

    /** @return list<array{platform:string, spend:float, spend_tax:float, purchase_value:float, roas:?float, accounts:int}> */
    private function platforms(AdsFilter $f): array
    {
        $f = $f->allSpend();

        return $this->q->sums($f, ['platform' => 'acc.platform'], fn ($b) => $b->selectRaw('COUNT(DISTINCT m.ad_account_id) as accounts')->orderByDesc('spend'))
            ->map(function (object $r) {
                $d = $this->q->derive($r);

                return [
                    'platform' => (string) $r->platform,
                    'spend' => $d['spend'], 'spend_tax' => $d['spend_tax'], 'purchase_value' => $d['purchase_value'],
                    'roas' => $d['roas'], 'accounts' => (int) $r->accounts,
                ];
            })->values()->all();
    }

    /** Currency of the accounts in the filter (first account by id), EGP when none. */
    public function currency(AdsFilter $f): string
    {
        if ($f->isEmpty()) {
            return 'EGP';
        }

        $q = DB::table('ad_accounts as acc')->whereNotNull('acc.currency');
        if ($f->platform !== null) {
            $q->where('acc.platform', $f->platform);
        }
        if ($f->accountIds !== null) {
            $q->whereIn('acc.id', $f->accountIds);
        }

        return (string) ($q->orderBy('acc.id')->value('acc.currency') ?? 'EGP');
    }
}
