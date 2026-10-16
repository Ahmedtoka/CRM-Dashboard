<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Launch\LaunchHandoff;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsToday;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    use BuildsAdsPages;

    public function __invoke(Request $request, AdsToday $today): Response
    {
        $user = $request->user();
        $week = AdsFilter::fromRequest($request, $user, 'last7');

        return Inertia::render('Ads/Today', [
            'filters' => $this->filterProps($week, $request),
            ...$this->commonProps($user, $week),
            'account_options' => $this->accountOptions($request, $week),
            'today' => $today->build($week, $user, ! ($request->filled('accounts') || $request->filled('buyer') || $request->filled('platform'))),
            // «جاهزة للنشر» (F4): drafts waiting for a buyer, per buyer for managers (two small indexed reads, not cached).
            'handoff' => LaunchHandoff::summary($user),
            'freshness' => $this->syncProps($week, false)['oldest']['last_synced_at'] ?? null,
        ]);
    }
}
