<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\NotificationEndpoints;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell's data source (spec §5.4, Dashboard Experience Task 14): the
 * latest persisted notifications, an unread count, and the count of
 * visible conversations with unread messages for the tab title badge.
 * Shared with the mobile app through `NotificationEndpoints`.
 */
class NotificationController extends Controller
{
    use NotificationEndpoints;

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->notificationsPayload($request->user()));
    }
}
