<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Access\AdsScope;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\CampaignTree;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    use BuildsAdsPages;

    /** Campaign → ad set → ad tree with roll-up metrics; scoped like every Ads report (buyers: their accounts and days). */
    public function __invoke(Request $request, CampaignTree $tree): Response
    {
        $filter = AdsFilter::fromRequest($request, $request->user());
        $sort = $this->oneOf($request->query('sort'), CampaignTree::SORTS, 'spend');

        return Inertia::render('Ads/Campaigns', [
            'filters' => $this->filterProps($filter) + ['sort' => $sort, 'accounts' => $this->pickedAccounts($request, $filter)],
            'account_options' => $this->accountOptions($request, $filter),
            ...$this->commonProps($request->user(), $filter),
            'tree' => $tree->build($filter, $sort),
        ]);
    }

    /** @return list<int> the account ids actually applied from `accounts[]` (already intersected with the user's scope) */
    private function pickedAccounts(Request $request, AdsFilter $filter): array
    {
        $asked = collect((array) ($request->query('accounts') ?? []))->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique();

        return $filter->accountIds === null ? $asked->values()->all() : ($asked->isEmpty() ? [] : array_values(array_intersect($filter->accountIds, $asked->all())));
    }

    /**
     * Accounts the user may pick (their scope and the platform filter), independent of the ones picked now so the chips stay.
     *
     * @return list<array{id:int,name:string,platform:string}>
     */
    private function accountOptions(Request $request, AdsFilter $filter): array
    {
        $allowed = app(AdsScope::class)->accountIds($request->user(), $filter->from, $filter->to);
        $q = AdAccount::query()->orderBy('name');
        if ($allowed !== null) {
            $q->whereIn('id', $allowed);
        }
        if ($filter->platform !== null) {
            $q->where('platform', $filter->platform);
        }

        return $q->get(['id', 'name', 'platform'])->map(fn (AdAccount $a) => ['id' => $a->id, 'name' => (string) $a->name, 'platform' => (string) $a->platform])->all();
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
