<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\NotificationEndpoints;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The bell on the mobile app: the web's payload, plus `?before_id=` for older pages of 30. */
class NotificationController extends Controller
{
    use NotificationEndpoints;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->notificationsPayload($request->user(), isset($data['before_id']) ? (int) $data['before_id'] : null));
    }
}
