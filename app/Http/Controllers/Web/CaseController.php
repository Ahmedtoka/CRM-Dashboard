<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\CaseEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportCaseResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Support cases dashboard (spec §4): a case list with filters/status tabs and a
 * status/assignment PATCH. Moderators only see cases on the platforms they can
 * access, same as the inbox and the orders list. `show`/`update` and the list query
 * live in `CaseEndpoints`, shared with the mobile app's `Api\V1\CaseController`.
 */
class CaseController extends Controller
{
    use CaseEndpoints;

    public function index(Request $request): Response
    {
        ['filters' => $filters, 'sort' => $sort, 'cases' => $page, 'counts' => $counts] = $this->casesPage($request);

        return Inertia::render('Cases', [
            'cases' => SupportCaseResource::collection($page),
            'filters' => array_merge(['type' => null, 'status' => null, 'q' => null, 'from' => null, 'to' => null, 'overdue' => null], $filters, [
                'overdue' => ! empty($filters['overdue']) ? true : null,
                'sort' => $sort?->value(),
            ]),
            'counts' => $counts,
            // Options for the drawer's "assigned to" select.
            'team' => User::query()->where('is_active', true)->inboxStaff()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
