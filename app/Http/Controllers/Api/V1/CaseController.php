<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\CaseEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportCaseResource;
use App\Models\SupportCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support cases on the mobile app (`/api/v1/cases`): the web Cases page's filters, scope, sort and
 * counts as JSON; `show`/`update` are the web endpoints (`CaseEndpoints`).
 */
class CaseController extends Controller
{
    use CaseEndpoints;

    public function index(Request $request): JsonResponse
    {
        ['cases' => $page, 'counts' => $counts] = $this->casesPage($request);

        return response()->json([
            // Resolved one by one: the resource's own `data` key defeats the automatic envelope (see `show`).
            'data' => $page->getCollection()->map(fn (SupportCase $c) => (new SupportCaseResource($c))->resolve($request))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => $counts,
        ]);
    }
}
