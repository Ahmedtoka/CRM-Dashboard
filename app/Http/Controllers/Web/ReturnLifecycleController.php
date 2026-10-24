<?php

namespace App\Http\Controllers\Web;

use App\Cases\Returns\ReturnLifecycle;
use App\Cases\Returns\ReturnTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportCaseResource;
use App\Models\SupportCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A moderator moves a return/exchange case one step (round 2026-10-24): POST /cases/{case}/return/{stage} with
 * the step's fact (pickup_date, refund_amount, exchange_order_id, reason). Same access as editing the case.
 * A refused step answers 422 with a `returns.errors.*` code the UI translates.
 */
class ReturnLifecycleController extends Controller
{
    public function advance(Request $request, SupportCase $supportCase, string $stage, ReturnLifecycle $lifecycle): JsonResponse
    {
        Gate::authorize('update', $supportCase);

        $input = $request->validate([
            'pickup_date' => ['sometimes', 'nullable', 'string', 'max:10'],
            'refund_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'exchange_order_id' => ['sometimes', 'nullable', 'integer'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        try {
            $case = $lifecycle->advance($supportCase, $stage, $request->user(), $input);
        } catch (ReturnTransitionException $e) {
            return response()->json([
                'message' => __('cases.returns.errors.'.$e->reason),
                'code' => $e->reason,
            ], 422);
        }

        $case->load(['customer', 'assignedTo', 'openedBy:id,name', 'queueEntry:id,assigned_user_id,is_test', 'order.items.variant', 'events.user:id,name']);

        return response()->json(['data' => (new SupportCaseResource($case))->resolve($request)]);
    }
}
