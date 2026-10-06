<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteSwitch;
use App\Ads\Launch\ApproveLaunch;
use App\Ads\Launch\LaunchPresenter;
use App\Ads\Launch\LaunchService;
use App\Ads\Launch\LaunchState;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdLaunch;
use App\Models\MediaBuyer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** موافقات الإطلاق (spec 3.5): the manager's decisions. Approve and bulk sit behind a 15-minute password re-auth (G3). */
class ApprovalController extends Controller
{
    public const REAUTH_SECONDS = 900;

    public function index(Request $request, LaunchPresenter $presenter, WriteActionService $writes): Response
    {
        $user = $request->user();
        abort_unless($user->hasAdsAuthority() || $user->isSupervisorOrAbove(), 403);
        $f = [
            'buyer' => is_numeric($request->query('buyer')) ? (string) $request->query('buyer') : null,
            'account' => is_numeric($request->query('account')) ? (string) $request->query('account') : null,
            'age' => in_array($request->query('age'), ['1d', '3d', '7d'], true) ? (string) $request->query('age') : null,
            'fails' => $request->boolean('fails'),
            'expiring' => $request->boolean('expiring'),
            'launch' => is_string($request->query('launch')) ? $request->query('launch') : null,
        ];

        $launches = AdLaunch::query()->with(LaunchPresenter::WITH)
            ->where(fn ($q) => $q->whereIn('state', [LaunchState::AwaitingApproval->value, LaunchState::Launching->value])
                ->orWhere(fn ($h) => $h->where('state', LaunchState::OnHold->value)->where('hold_from_state', LaunchState::AwaitingApproval->value)))
            ->when($f['buyer'] !== null, fn ($q) => $q->where('reviewer_buyer_id', (int) $f['buyer']))
            ->when($f['account'] !== null, fn ($q) => $q->where('ad_account_id', (int) $f['account']))
            ->when($f['age'] !== null, fn ($q) => $q->where('awaiting_at', '<=', now()->subDays((int) $f['age'])))
            ->when($f['expiring'], fn ($q) => $q->where('expires_at', '<=', now()->addDay()))
            ->orderBy('awaiting_at')->orderBy('id')->limit(50)->get()
            ->map(fn (AdLaunch $l) => $presenter->row($l, $user, ['checks' => 'fresh', 'publications' => true, 'history' => true]));
        if ($f['fails']) {
            $launches = $launches->filter(fn (array $r) => collect($r['checks'])->contains('level', 'block'));
        }

        return Inertia::render('Ads/Approvals', [
            'filters' => $f,
            'launches' => $launches->values()->all(),
            'options' => [
                'buyers' => MediaBuyer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all(),
                'accounts' => AdAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->all(),
            ],
            'approvalsLeft' => $user->hasAdsAuthority() ? $writes->activationsLeftToday($user) : 0,
            'writesOn' => WriteSwitch::enabled(),
            'canApprove' => $user->hasAdsAuthority(),
            'isAdmin' => $user->isAdmin(),
            'reasons' => LaunchService::REASONS,
        ]);
    }

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

    /** The safe plan; the page runs it one approval at a time and draws the progress bar (A5). */
    public function bulk(Request $request, ApproveLaunch $approve): JsonResponse
    {
        return response()->json(['ok' => true] + $approve->safePlan($request->user()));
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
