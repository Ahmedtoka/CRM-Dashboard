<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Reports\AdsFilter;
use App\Http\Controllers\Controller;
use App\Inbox\Outcomes\ChatFunnel;
use App\Models\Ad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The chat funnel of up to 50 ads for the ad drawer (control room S3). Out-of-scope ads are left out (never a 403 that leaks they exist). */
class ChatFunnelController extends Controller
{
    public function __invoke(Request $request, ChatFunnel $funnel): JsonResponse
    {
        $data = $request->validate(['ads' => ['required', 'array', 'max:50'], 'ads.*' => ['integer']]);
        $filter = AdsFilter::fromRequest($request, $request->user());

        $ids = Ad::query()->whereKey(array_map('intval', $data['ads']))
            ->when($filter->accountIds !== null, fn ($q) => $q->whereIn('ad_account_id', $filter->accountIds))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return response()->json([
            'data' => (object) $funnel->forAds($ids, $filter->startUtc(), $filter->endUtc()),
            'range' => ['from' => $filter->fromDate(), 'to' => $filter->toDate()],
        ]);
    }
}
