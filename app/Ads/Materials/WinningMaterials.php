<?php

namespace App\Ads\Materials;

use App\Ads\Access\AdsScope;
use App\Ads\Reports\WinnerScorer;
use App\Models\AdMaterial;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The winners library (round 3): the materials whose linked ads are «winner» over MaterialPerformance's 30-day
 * window — or «promising» too when fewer than three materials win — sorted by ROAS, at most 12. A viewer who
 * cannot see spend gets nothing; a buyer's tiers and ads are already scoped to their own accounts.
 */
final class WinningMaterials
{
    public const MAX = 12;

    public const MIN_WINNERS = 3;

    public function __construct(
        private readonly AdsScope $scope,
        private readonly MaterialService $service,
        private readonly MaterialPerformance $performance,
        private readonly WinnerScorer $scorer,
    ) {}

    /** @return list<array<string, mixed>> */
    public function top(User $user, int $max = self::MAX): array
    {
        if (! $this->scope->canSeeSpend($user)) {
            return [];
        }
        $tiers = collect($this->scorer->tiers($this->performance->filter($user)));
        $winners = $tiers->filter(fn (string $t) => $t === 'winner')->keys()->all();
        $accepted = ['winner'];
        $materials = $this->linked($winners);
        if ($materials->count() < self::MIN_WINNERS) {
            $promising = $tiers->filter(fn (string $t) => $t === 'promising')->keys()->all();
            if ($promising !== []) {
                $accepted[] = 'promising';
                $materials = $this->linked([...$winners, ...$promising]);
            }
        }
        if ($materials->isEmpty()) {
            return [];
        }
        $canReuse = MaterialService::canAuthor($user) || MaterialService::canOperate($user);

        return collect($this->service->rows($materials, $user))
            // rows() re-scores through the viewer's own scope: keep only what still ranks as accepted for them
            ->filter(fn (array $r) => in_array($r['performance']['winner_tier'] ?? null, $accepted, true))
            ->sortBy([fn (array $a, array $b) => ($b['performance']['roas'] ?? -1.0) <=> ($a['performance']['roas'] ?? -1.0), fn (array $a, array $b) => $b['id'] <=> $a['id']])
            ->take($max)
            ->map(fn (array $r) => [
                'id' => $r['id'],
                'title' => $r['title'],
                'product_title' => $r['product']['title'] ?? null,
                'thumbnail' => $r['thumb_url'],
                'status' => $r['status'],
                'performance' => [
                    'spend_tax' => $r['performance']['spend_tax'],
                    'purchase_value' => $r['performance']['purchase_value'],
                    'roas' => $r['performance']['roas'],
                    'real_orders' => $r['performance']['real_orders'],
                    'winner_tier' => $r['performance']['winner_tier'],
                ],
                'ads' => array_map(fn (array $a) => ['id' => $a['id'], 'name' => $a['name'], 'platform' => $a['platform']], $r['ads']),
                'can_reuse' => $canReuse,
            ])->values()->all();
    }

    /**
     * @param  list<int>  $adIds
     * @return Collection<int, AdMaterial>
     */
    private function linked(array $adIds): Collection
    {
        if ($adIds === []) {
            return new Collection;
        }

        return $this->service->withRelations(AdMaterial::query()->whereHas('ads', fn ($q) => $q->whereIn('ads.id', $adIds)))->get();
    }
}
