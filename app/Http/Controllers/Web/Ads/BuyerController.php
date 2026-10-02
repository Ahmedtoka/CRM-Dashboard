<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Access\AdsScope;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\BuyerScorecard;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\MediaBuyer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerController extends Controller
{
    use BuildsAdsPages;

    public function index(Request $request, BuyerScorecard $cards): Response
    {
        $filter = AdsFilter::fromRequest($request, $request->user());

        return Inertia::render('Ads/Buyers', [
            'filters' => $this->filterProps($filter),
            'cards' => $cards->build($filter),
        ]);
    }

    public function show(Request $request, MediaBuyer $buyer, BuyerScorecard $cards, AdsScope $scope): Response
    {
        $user = $request->user();
        abort_unless($user->isSupervisorOrAbove() || $scope->buyerFor($user)?->id === $buyer->id, 403);

        $filter = AdsFilter::fromRequest($request, $user);

        return Inertia::render('Ads/BuyerShow', [
            'filters' => $this->filterProps($filter),
            'buyer' => ['id' => $buyer->id, 'name' => $buyer->name, 'color' => $buyer->color],
            'detail' => $cards->detail($buyer, $filter),
        ]);
    }
}
