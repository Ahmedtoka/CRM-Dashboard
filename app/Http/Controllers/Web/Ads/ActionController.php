<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Access\AdsScope;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActionController extends Controller
{
    use BuildsAdsPages;

    public const LOG_LIMIT = 100;

    /** Days the suggestions look back. */
    public const SUGGEST_DAYS = 14;

    /** Stop suggestions first, then the action log; both scoped like every Ads report (buyers: their accounts). */
    public function index(Request $request, StopAdvisor $advisor, AdWriteService $writer): Response
    {
        $user = $request->user();
        $filter = AdsFilter::fromRequest($request, $user);
        $filter = $filter->with(['from' => $filter->to->subDays(self::SUGGEST_DAYS - 1)]);

        $found = $advisor->suggest($filter);
        $accounts = AdAccount::query()->whereIn('id', array_unique(array_column($found, 'account_id')))->get(['id', 'is_active', 'platform', 'external_id']);
        $can = $writer->canWriteMany($user, $accounts);
        $suggestions = array_map(fn (array $s) => $s + ['can_write' => $can[$s['account_id']] ?? false], $found);

        $allowed = app(AdsScope::class)->accountIds($user);
        $log = AdAction::query()->with(['user:id,name', 'account:id,name'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('ad_account_id', $allowed))
            ->orderByDesc('id')->limit(self::LOG_LIMIT)->get()
            ->map(fn (AdAction $a) => [
                'id' => $a->id, 'at' => $a->created_at?->toIso8601String(), 'user' => $a->user?->name,
                'platform' => $a->platform, 'account' => (string) ($a->account?->name ?? $a->account_name ?? ''), 'level' => $a->level,
                'name' => (string) $a->name, 'from_status' => $a->from_status, 'to_status' => $a->to_status,
                'reason' => $a->reason, 'result' => $a->result, 'error' => $a->error,
            ])->all();

        return Inertia::render('Ads/Actions', [
            'currency' => app(AdsOverview::class)->currency($filter),
            'days' => self::SUGGEST_DAYS,
            'suggestions' => $suggestions,
            'log' => $log,
        ]);
    }

    /** Stop or Run one campaign, ad set or ad. Scope is checked in the service (403 outside it, 422 with the platform's message). */
    public function status(Request $request, AdWriteService $writer): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('ad_accounts', 'id')],
            'level' => ['required', Rule::in(AdWriteService::LEVELS)],
            'external_id' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(AdWriteService::STATUSES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $action = $writer->setStatus($request->user(), AdAccount::findOrFail($data['account_id']), $data['level'], $data['external_id'], $data['status'], $data['reason'] ?? null);

        return response()->json(['ok' => true, 'status' => $action->to_status, 'message' => __($action->to_status === 'PAUSED' ? 'ads.flash.stopped' : 'ads.flash.resumed')]);
    }
}
