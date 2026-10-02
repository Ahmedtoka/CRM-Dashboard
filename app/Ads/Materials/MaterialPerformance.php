<?php

namespace App\Ads\Materials;

use App\Ads\Access\AdsScope;
use App\Ads\AdsSettings;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use App\Ads\Reports\WinnerScorer;
use App\Enums\UserRole;
use App\Models\AdMaterial;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Per-material performance over the last 30 days (Cairo): the sum over its linked ads, in batch (no per-material
 * queries). A media buyer only counts their own metric rows (AdsFilter restriction). `winner_tier` is the best
 * tier among the linked ads from WinnerScorer over the same window.
 */
final class MaterialPerformance
{
    public const DAYS = 30;

    private const TIER_RANK = ['winner' => 3, 'promising' => 2, 'neutral' => 1, 'loser' => 0];

    /** @var array<int, string> ad id => tier, memoised per user for the life of this instance */
    private array $tiers = [];

    private ?int $tiersFor = null;

    public function __construct(
        private readonly AdsScope $scope,
        private readonly AdsQuery $q,
        private readonly WinnerScorer $scorer,
        private readonly AdsSettings $settings,
    ) {}

    /**
     * @param  Collection<int, AdMaterial>  $materials  with `ads` loaded
     * @return array<int, array{spend:float, spend_tax:float, purchase_value:float, roas:?float, purchases:float, real_orders:int, winner_tier:?string}|null>
     *                                                                                                                                                        material id => performance (null: no linked ads, or the user may not see spend)
     */
    public function forMaterials(Collection $materials, User $user): array
    {
        $out = [];
        $adIds = $materials->flatMap(fn ($m) => $m->ads->pluck('id'))->unique()->values()->all();
        if (! $this->scope->canSeeSpend($user) || $adIds === []) {
            foreach ($materials as $m) {
                $out[$m->id] = null;
            }

            return $out;
        }

        $filter = $this->filter($user);
        $sums = $this->q->sums($filter, ['ad_id' => 'm.ad_id'], fn ($b) => $b->whereIn('m.ad_id', $adIds))->keyBy(fn ($r) => (int) $r->ad_id);
        $orders = $this->q->orders($filter)->filter(fn (array $o) => $o['ad_id'] !== null && in_array($o['ad_id'], $adIds, true))->groupBy('ad_id')->map->count();
        $tiers = $this->tiers($filter, $user);

        $taxRate = $this->settings->taxRate(); // once, not per material

        foreach ($materials as $m) {
            $ids = $m->ads->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                $out[$m->id] = null;

                continue;
            }
            $sum = ['spend' => 0.0, 'purchase_value' => 0.0, 'purchases' => 0.0];
            $best = null;
            foreach ($ids as $id) {
                if (isset($sums[$id])) {
                    foreach ($sum as $k => $v) {
                        $sum[$k] = $v + (float) $sums[$id]->{$k};
                    }
                }
                $tier = $tiers[$id] ?? null;
                if ($tier !== null && ($best === null || self::TIER_RANK[$tier] > self::TIER_RANK[$best])) {
                    $best = $tier;
                }
            }
            $out[$m->id] = [
                'spend' => round($sum['spend'], 2), 'spend_tax' => round($sum['spend'] * (1 + $taxRate), 2),
                'purchase_value' => round($sum['purchase_value'], 2), 'roas' => AdsQuery::ratio($sum['purchase_value'], $sum['spend'], 2),
                'purchases' => round($sum['purchases'], 2),
                'real_orders' => (int) collect($ids)->sum(fn ($id) => $orders[$id] ?? 0),
                'winner_tier' => $best,
            ];
        }

        return $out;
    }

    /** The last 30 days ending today (Cairo), restricted to the user's accounts and, for a buyer, their own rows. */
    private function filter(User $user): AdsFilter
    {
        $to = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();
        $from = $to->subDays(self::DAYS - 1);
        $restrict = $user->role === UserRole::MediaBuyer ? ($this->scope->buyerFor($user)?->id ?? 0) : null;

        return new AdsFilter($from, $to, null, null, $this->scope->accountIds($user, $from, $to), $restrict);
    }

    /** @return array<int, string> */
    private function tiers(AdsFilter $filter, User $user): array
    {
        if ($this->tiersFor !== $user->id) {
            $this->tiers = [];
            foreach ($this->scorer->build($filter) as $row) {
                $this->tiers[(int) $row['ad']['id']] = $row['tier'];
            }
            $this->tiersFor = $user->id;
        }

        return $this->tiers;
    }
}
