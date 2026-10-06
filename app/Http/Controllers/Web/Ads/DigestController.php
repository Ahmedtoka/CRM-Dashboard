<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Alerts\Digest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The 09:00 digest card of /ads (D14). Scope per AlertScope / AdsScope; ads:report only. */
class DigestController extends Controller
{
    public function __invoke(Request $request, Digest $digest): JsonResponse
    {
        return response()->json($digest->forUser($request->user()));
    }
}
