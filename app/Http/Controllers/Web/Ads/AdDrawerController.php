<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Control\WriteActionLog;
use App\Ads\Reports\AdRowEnricher;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\RunningCreatives;
use App\Ads\Reports\WinnerScorer;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** One ad for the drawer (U 3.4): row + preview, reasons, open decisions, write history; funnel filled by S3. */
class AdDrawerController extends Controller
{
    public function __invoke(Request $request, Ad $ad, RunningCreatives $creatives, AdRowEnricher $enricher, WinnerScorer $scorer,
        StopAdvisor $advisor, WriteActionLog $log, AdWriteService $writes): JsonResponse
    {
        $user = $request->user();
        $filter = AdsFilter::fromRequest($request, $user, 'last7')->allSpend();
        abort_if($filter->accountIds !== null && ! in_array($ad->ad_account_id, $filter->accountIds, true), 404);

        $row = $creatives->detail($ad, $filter);
        $preview = $row['preview_html'];
        $row = $enricher->enrich([$row], $filter, $user)[0] + ['preview_html' => $preview];

        $recent = $filter->with(['from' => $filter->to->subDays(13), 'accountIds' => [$ad->ad_account_id], 'activeCampaignsOnly' => true]);
        $decisions = collect($advisor->suggest($recent))->where('ad_id', $ad->id)
            ->map(fn (array $s) => ['kind' => 'stop_suggestion', 'reasons' => $s['reasons']])->values()->all();

        return response()->json([
            'ad' => $row,
            'reasons' => $scorer->reasonsFor($ad->id, $filter),
            'decisions' => $decisions,
            'history' => $log->rows($user, ['ad' => ['account_id' => $ad->ad_account_id, 'external_id' => (string) $ad->external_id]], 20),
            'funnel' => null,
            'levels' => $writes->allowedLevels($user),
        ]);
    }
}
