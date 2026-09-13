<?php

namespace App\Http\Controllers\Web;

use App\Analytics\PresenceTracker;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function heartbeat(Request $request, PresenceTracker $presence): JsonResponse
    {
        $presence->heartbeat($request->user(), 'web');

        return response()->json(['data' => ['online_user_ids' => $presence->onlineUserIds()]]);
    }
}
