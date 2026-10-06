<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Launch\ApproveLaunch;
use App\Ads\Launch\LaunchService;
use App\Http\Controllers\Controller;
use App\Models\AdLaunch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** موافقات الإطلاق (spec 3.5): the manager's decisions. Approve and bulk sit behind a 15-minute password re-auth (G3). */
class ApprovalController extends Controller
{
    public const REAUTH_SECONDS = 900;

    public function approve(Request $request, AdLaunch $launch, ApproveLaunch $approve): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'checks_hash' => ['required', 'string', 'size:64'],
            'ack_warnings' => ['present', 'array'],
            'ack_warnings.*' => ['string', 'max:60'],
        ]);
        $r = $approve->approve($request->user(), $launch, (int) $data['revision'], $data['checks_hash'], array_values($data['ack_warnings']));
        $l = $r['launch'];

        return response()->json([
            'ok' => true, 'message' => __('ads.launch.flash.approved_'.$l->state->value),
            'launch' => ['id' => $l->public_id, 'state' => $l->state->value, 'revision' => $l->revision],
            'ads' => $r['ads'], 'self_approved' => $r['self_approved'],
        ]);
    }

    public function sendBack(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $data = $this->reason($request);
        $l = $launches->managerReturn($request->user(), $launch, $data['code'], $data['text'] ?? null);

        return response()->json(['ok' => true, 'message' => __('ads.launch.flash.returned'), 'launch' => ['id' => $l->public_id, 'state' => $l->state->value, 'revision' => $l->revision]]);
    }

    public function reject(Request $request, AdLaunch $launch, LaunchService $launches): JsonResponse
    {
        $data = $this->reason($request);
        $l = $launches->reject($request->user(), $launch, $data['code'], $data['text'] ?? null);

        return response()->json(['ok' => true, 'message' => __('ads.launch.flash.rejected'), 'launch' => ['id' => $l->public_id, 'state' => $l->state->value, 'revision' => $l->revision]]);
    }

    /** @return array{code: string, text?: ?string} */
    private function reason(Request $request): array
    {
        return $request->validate(['code' => ['required', 'string', Rule::in(LaunchService::REASONS)], 'text' => ['nullable', 'string', 'max:1000']]);
    }
}
