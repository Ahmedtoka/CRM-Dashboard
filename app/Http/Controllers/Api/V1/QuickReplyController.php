<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Inbox\QuickReplyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickReplyController extends Controller
{
    public function index(Request $request, QuickReplyCatalog $catalog): JsonResponse
    {
        $platform = Platform::tryFrom((string) $request->query('platform', ''));

        return response()->json(['data' => $catalog->toArray($request->user(), $platform)]);
    }
}
