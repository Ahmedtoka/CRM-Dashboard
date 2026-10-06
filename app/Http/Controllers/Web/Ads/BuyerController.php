<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Access\AdsScope;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\BuyerScorecard;
use App\Ads\Reports\RevenueSummary;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\MediaBuyer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerController extends Controller
{
    use BuildsAdsPages;

    public function show(Request $request, MediaBuyer $buyer, BuyerScorecard $cards, AdsScope $scope, RevenueSummary $summary): Response
    {
        $user = $request->user();
        abort_unless($user->isSupervisorOrAbove() || $scope->buyerFor($user)?->id === $buyer->id, 403);

        $filter = AdsFilter::fromRequest($request, $user);
        $buyerFilter = $filter->restrictBuyerId !== null && $filter->restrictBuyerId !== $buyer->id
            ? $filter : $filter->with(['buyerId' => $buyer->id]);

        return Inertia::render('Ads/BuyerShow', [
            'filters' => $this->filterProps($filter),
            ...$this->commonProps($user, $filter),
            'buyer' => ['id' => $buyer->id, 'name' => $buyer->name, 'color' => $buyer->color],
            'detail' => $cards->detail($buyer, $filter),
            'summary' => $summary->build($buyerFilter, $user->isSupervisorOrAbove()),
        ]);
    }
}
