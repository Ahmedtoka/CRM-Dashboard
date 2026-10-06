<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\CampaignTree;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
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
            'filters' => $this->filterProps($filter, $request) + ['sort' => $sort],
            'account_options' => $this->accountOptions($request, $filter),
            ...$this->commonProps($request->user(), $filter),
            'tree' => $this->withCanWrite($tree->build($filter, $sort), $request),
        ]);
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
