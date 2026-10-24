<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Cases\FollowUpList;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «المتابعة» (queue ops): the open-cases follow-up page. The list itself is `FollowUpList`; the row
 * actions reuse the existing endpoints (`PATCH /cases/{id}` for take/resolve, the conversation notes
 * endpoint for a note), so nothing here changes a case.
 */
class CaseFollowUpController extends Controller
{
    public function index(Request $request, FollowUpList $list): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(SupportCase::TYPES)],
            'owner' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
        ]);

        ['rows' => $rows, 'counts' => $counts] = $list->build($request->user(), $filters);

        return Inertia::render('Cases/FollowUp', [
            'rows' => $rows,
            'counts' => $counts,
            'filters' => [
                'type' => $filters['type'] ?? null,
                'owner' => isset($filters['owner']) ? (int) $filters['owner'] : null,
                'overdue' => ! empty($filters['overdue']) ? true : null,
                'mine' => ! empty($filters['mine']) ? true : null,
            ],
            // The owner filter and the drawer's "assigned to" select share the same team list.
            'team' => User::query()->where('is_active', true)->inboxStaff()->orderBy('name')->get(['id', 'name']),
            'me' => $request->user()->id,
        ]);
    }
}
