<?php

namespace App\Ads\Reports;

use App\Ads\Naming;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;

/**
 * Campaign → ad set → ad tree with roll-up metrics (spec 1.5).
 *
 * One grouped query over the filtered metric rows (so a media buyer only gets rows of accounts assigned to them on
 * those dates, exactly as every other report), grouped by ad id only (ONLY_FULL_GROUP_BY safe); names, statuses and the
 * hierarchy come from second queries and the tree is assembled in PHP. Parent metrics are derived from the summed raw
 * figures of their ads, never from averaged child ratios. Ads with no campaign or ad set land under a placeholder node
 * with id 0, one per account / per campaign, flagged `placeholder` (the client labels it; no actions on it).
 *
 * Node = {level, placeholder, id, external_id, account_id, account, platform, name, status, objective, naming_ok, metrics, children};
 * ad nodes also carry `ad_id` (= id) and `trend`; metrics = AdsQuery::derive + real_orders.
 */
final class CampaignTree
{
    public const SORTS = ['spend', 'roas'];

    public function __construct(private readonly AdsQuery $q, private readonly AdInsights $insights) {}

    /** @return list<array<string, mixed>> */
    public function build(AdsFilter $f, string $sort = 'spend'): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'spend';
        $sums = $this->q->sums($f, ['ad_id' => 'm.ad_id'])->keyBy(fn ($r) => (int) $r->ad_id);
        if ($sums->isEmpty()) {
            return [];
        }

        $ads = Ad::query()->whereIn('id', $sums->keys()->all())->get()->keyBy('id');
        $adSets = AdSet::query()->whereIn('id', $ads->pluck('ad_set_id')->filter()->unique()->all())->get()->keyBy('id');
        $campaignIds = $ads->pluck('ad_campaign_id')->merge($adSets->pluck('ad_campaign_id'))->filter()->unique()->all();
        $campaigns = AdCampaign::query()->whereIn('id', $campaignIds)->get()->keyBy('id');
        $accounts = AdAccount::query()->whereIn('id', $ads->pluck('ad_account_id')->unique()->all())->get(['id', 'name', 'platform'])->keyBy('id');
        $real = $this->q->orders($f)->whereNotNull('ad_id')->countBy('ad_id');
        $trends = $this->insights->forAds($sums->keys()->all(), $f->to);

        // campaign id => ad set id => ad id => raw sums (0 = no campaign / no ad set)
        $grouped = [];
        foreach ($sums as $adId => $s) {
            $ad = $ads->get($adId);
            if ($ad === null) {
                continue;
            }
            $adSet = $ad->ad_set_id !== null ? $adSets->get($ad->ad_set_id) : null;
            $campaignId = (int) ($ad->ad_campaign_id ?? $adSet?->ad_campaign_id ?? 0);
            // No campaign: one placeholder per account, so account_id is always the right one.
            $key = $campaignId > 0 ? $campaignId : 'p'.$ad->ad_account_id;
            $grouped[$key][(int) ($adSet?->id ?? 0)][$adId] = $s;
        }

        $nodes = [];
        foreach ($grouped as $campaignKey => $sets) {
            $campaign = is_int($campaignKey) ? $campaigns->get($campaignKey) : null;
            $firstAdId = array_key_first(reset($sets));
            $accountId = (int) ($campaign?->ad_account_id ?? $ads->get($firstAdId)->ad_account_id);
            $account = $accounts->get($accountId);

            $setNodes = [];
            foreach ($sets as $setId => $adSums) {
                $adNodes = [];
                foreach ($adSums as $adId => $s) {
                    $ad = $ads->get($adId);
                    $adAccount = $accounts->get($ad->ad_account_id);
                    $count = (int) ($real[$adId] ?? 0);
                    $adNodes[] = [
                        'level' => 'ad', 'placeholder' => false, 'id' => $adId, 'ad_id' => $adId, 'external_id' => (string) $ad->external_id,
                        'account_id' => (int) $ad->ad_account_id, 'account' => (string) ($adAccount?->name ?? ''),
                        'platform' => (string) ($adAccount?->platform ?? ''),
                        'name' => (string) $ad->name, 'status' => $ad->effective_status ?? $ad->status, 'objective' => null,
                        'naming_ok' => true,
                        'metrics' => $this->metrics($s, $count),
                        'trend' => $trends[$adId]['trend'] ?? null,
                        'children' => [],
                        '_raw' => $s, '_real' => $count,
                    ];
                }
                $adSet = $adSets->get($setId);
                $setNodes[] = $this->parent('adset', $adSet, $accountId, $account, $adNodes, $adSet === null || Naming::checkAdSet((string) $adSet->name), null);
            }
            $nodes[] = $this->parent('campaign', $campaign, $accountId, $account, $setNodes, $campaign === null || Naming::checkCampaign((string) $campaign->name), $campaign?->objective);
        }

        return $this->finish($this->sortNodes($nodes, $sort));
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function parent(string $level, AdSet|AdCampaign|null $model, int $accountId, ?AdAccount $account, array $children, bool $namingOk, ?string $objective): array
    {
        $raw = [];
        $real = 0;
        foreach ($children as $c) {
            foreach ((array) $c['_raw'] as $k => $v) {
                $raw[$k] = ($raw[$k] ?? 0) + $v;
            }
            $real += $c['_real'];
        }

        return [
            'level' => $level, 'placeholder' => $model === null, 'id' => (int) ($model?->id ?? 0), 'external_id' => (string) ($model?->external_id ?? ''),
            'account_id' => $accountId, 'account' => (string) ($account?->name ?? ''), 'platform' => (string) ($account?->platform ?? ''),
            'name' => (string) ($model?->name ?? ''), 'status' => $model?->status, 'objective' => $objective,
            'naming_ok' => $namingOk,
            'metrics' => $this->metrics($raw, $real),
            'children' => $children,
            '_raw' => $raw, '_real' => $real,
        ];
    }

    /**
     * @param  array<string, mixed>|object  $raw
     * @return array<string, mixed>
     */
    private function metrics(array|object $raw, int $real): array
    {
        return $this->q->derive($raw) + ['real_orders' => $real];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function sortNodes(array $nodes, string $sort): array
    {
        foreach ($nodes as &$n) {
            $n['children'] = $this->sortNodes($n['children'], $sort);
        }
        unset($n);

        usort($nodes, function (array $a, array $b) use ($sort) {
            $x = $a['metrics'][$sort];
            $y = $b['metrics'][$sort];
            if ($x === null || $y === null) {
                return ($x === null) <=> ($y === null); // nulls last
            }

            return ($y <=> $x) ?: ($b['metrics']['spend'] <=> $a['metrics']['spend']);
        });

        return $nodes;
    }

    /**
     * Drops the raw bookkeeping keys.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function finish(array $nodes): array
    {
        foreach ($nodes as &$n) {
            unset($n['_raw'], $n['_real']);
            $n['children'] = $this->finish($n['children']);
        }

        return $nodes;
    }
}
