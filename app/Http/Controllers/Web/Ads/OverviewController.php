<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\RevenueSummary;
use App\Ads\Reports\TopAccounts;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    use BuildsAdsPages;

    public function __invoke(Request $request, AdsOverview $overview, TopAccounts $topAccounts, RevenueSummary $summary): Response
    {
        $user = $request->user();
        $filter = AdsFilter::fromRequest($request, $user);

        return Inertia::render('Ads/Overview', [
            'filters' => $this->filterProps($filter),
            ...$this->commonProps($user, $filter),
            'overview' => $overview->build($filter),
            'summary' => $summary->build($filter, $user->isSupervisorOrAbove()),
            'top_accounts' => $topAccounts->build($filter),
            'sync' => $this->syncProps($filter, $user->isSupervisorOrAbove()),
        ]);
    }
}
