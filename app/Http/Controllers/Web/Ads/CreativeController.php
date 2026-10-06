<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\RunningCreatives;
use App\Ads\Reports\WinnerScorer;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The creative JSON (old modal). The Creatives and Winners pages are the explorer now (LegacyAdsRedirectController). */
class CreativeController extends Controller
{
    /** One creative with its range numbers and preview markup, for the modal. */
    public function show(Request $request, Ad $ad, RunningCreatives $creatives, WinnerScorer $scorer): JsonResponse
    {
        // A direct link to one ad shows its numbers and reasons whatever its campaign status (D1).
        $filter = AdsFilter::fromRequest($request, $request->user())->allSpend();
        // Out of scope reads as not found: a buyer never learns that other accounts' ads exist.
        abort_if($filter->accountIds !== null && ! in_array($ad->ad_account_id, $filter->accountIds, true), 404);

        return response()->json($creatives->detail($ad, $filter) + ['reasons' => $scorer->reasonsFor($ad->id, $filter)]);
    }
}
