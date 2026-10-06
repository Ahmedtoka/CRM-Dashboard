<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Launch\SlotService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Open slots: list the ad sets the viewer may open, and open / close one (spec 3.2). */
class SlotController extends Controller
{
    public function index(Request $request, SlotService $slots): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasAdsAuthority() || $user->role === UserRole::MediaBuyer, 403);

        return response()->json(['data' => $slots->slotsFor($user)]);
    }

    public function toggle(Request $request, AdSet $adSet, SlotService $slots): JsonResponse
    {
        $data = $request->validate(['open' => ['required', 'boolean']]);
        $set = $slots->toggle($request->user(), $adSet, (bool) $data['open']);

        return response()->json([
            'ok' => true,
            'message' => __($set->open_for_drafts_at !== null ? 'ads.launch.flash.slot_opened' : 'ads.launch.flash.slot_closed'),
            'slot' => ['id' => $set->id, 'open' => $set->open_for_drafts_at !== null],
        ]);
    }
}
