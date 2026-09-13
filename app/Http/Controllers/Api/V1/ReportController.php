<?php

namespace App\Http\Controllers\Api\V1;

use App\Analytics\MetricsService;
use App\Analytics\PresenceTracker;
use App\Http\Controllers\Concerns\ReportEndpoints;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ReportEndpoints;

    public function team(Request $request, MetricsService $metrics, PresenceTracker $presence): JsonResponse
    {
        return response()->json($this->teamReport($request, $metrics, $presence));
    }

    public function user(Request $request, MetricsService $metrics, User $user): JsonResponse
    {
        return response()->json($this->userReport($request, $metrics, $user));
    }

    public function me(Request $request, MetricsService $metrics): JsonResponse
    {
        return response()->json($this->userReport($request, $metrics, $request->user()));
    }

    public function bot(Request $request, MetricsService $metrics): JsonResponse
    {
        return response()->json($this->botReport($request, $metrics));
    }
}
