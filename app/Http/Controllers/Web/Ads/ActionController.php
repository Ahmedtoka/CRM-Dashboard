<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Access\AdsScope;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Platforms\SecretScrubber;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Http\Controllers\Concerns\BuildsAdsPages;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
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
        $accounts = AdAccount::query()->whereIn('id', array_unique(array_column($found, 'account_id')))->get(['id', 'is_active', 'write_enabled', 'platform', 'external_id']);
        $can = $writer->canWriteMany($user, $accounts);
        $suggestions = array_map(fn (array $s) => $s + ['can_write' => $can[$s['account_id']] ?? false], $found);

        // One history table since B1: slice-1 rows were copied in (source=legacy). Proposals never shown.
        $allowed = app(AdsScope::class)->accountIds($user);
        $log = AdWriteAction::query()->with(['confirmer:id,name', 'proposer:id,name', 'account:id,name'])
            ->whereNotIn('state', [AdWriteAction::PROPOSED, AdWriteAction::EXPIRED, AdWriteAction::CANCELLED, AdWriteAction::SUPERSEDED])
            ->when($allowed !== null, fn ($q) => $q->whereIn('ad_account_id', $allowed))
            ->orderByRaw('COALESCE(confirmed_at, created_at) DESC')->orderByDesc('id')->limit(self::LOG_LIMIT)->get()
            ->map(fn (AdWriteAction $a) => [
                'id' => $a->id, 'at' => ($a->confirmed_at ?? $a->created_at)?->toIso8601String(), 'user' => ($a->confirmer ?? $a->proposer)?->name,
                'platform' => $a->platform, 'account' => (string) ($a->account?->name ?? $a->account_name ?? ''), 'level' => $a->target_level,
                'name' => (string) $a->target_name, 'from_status' => $a->from_status, 'to_status' => $a->to_status === 'active' ? 'ACTIVE' : 'PAUSED',
                'reason' => $a->reason, 'result' => self::logResult($a->state), 'error' => self::logError($a),
            ])->all();

        return Inertia::render('Ads/Actions', [
            'currency' => app(AdsOverview::class)->currency($filter),
            ...$this->bannerProps($filter),
            'days' => self::SUGGEST_DAYS,
            'suggestions' => $suggestions,
            'log' => $log,
        ]);
    }

    /**
     * The legacy Stop / Run endpoint (B5 shim): propose + confirm through the write pipeline in one request
     * (source=legacy), so every policy, Run-guard and Stop exemption applies. Keeps the slice-1 response shape
     * ({ok, status, message} + action_id); refusals use the stable refusal shape, whose errors.status[0] the old dialog
     * shows. Removal: after 14 days without an ads.legacy_write_endpoint log line.
     */
    public function status(Request $request, WriteActionService $service): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('ad_accounts', 'id')],
            'level' => ['required', Rule::in(AdWriteService::LEVELS)],
            'external_id' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(AdWriteService::STATUSES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();
        $key = $this->legacyKey($request, $data);
        $x = null;

        try {
            $result = $service->propose($user, AdAccount::findOrFail($data['account_id']), $data['level'], $data['external_id'], $data['status'],
                $data['reason'] ?? null, $key, 'legacy');
            $x = $result['action'];
            if ($x->state === AdWriteAction::PROPOSED) {
                $x = $service->confirm($user, $x, $x->diff_hash);
            }
        } finally {
            Log::info('ads.legacy_write_endpoint', ['user_id' => $user->id, 'public_id' => $x?->public_id]);
        }

        $status = $x->to_status === 'active' ? 'ACTIVE' : 'PAUSED';

        return match ($x->state) {
            AdWriteAction::SUCCEEDED => response()->json(['ok' => true, 'status' => $status, 'action_id' => $x->public_id, 'message' => __(match (true) {
                $x->isStop() => 'ads.flash.stopped',
                (bool) ($x->outcome['noop'] ?? false) => 'ads.write.notes.already_active',
                default => 'ads.flash.resumed',
            })]),
            AdWriteAction::EXECUTING, AdWriteAction::UNKNOWN => response()->json(['ok' => false, 'pending' => true, 'status' => $status, 'action_id' => $x->public_id,
                'message' => WriteActionController::pendingMessage($x)], 202),
            default => $this->refused(WriteActionController::failure($x), $x),
        };
    }

    /** @return 'ok'|'error'|'pending' */
    private static function logResult(string $state): string
    {
        return match ($state) {
            AdWriteAction::SUCCEEDED, AdWriteAction::ROLLED_BACK => 'ok',
            AdWriteAction::EXECUTING, AdWriteAction::UNKNOWN => 'pending',
            default => 'error',
        };
    }

    /**
     * The same message the user got: the translated refusal with its stored details (never a raw :placeholder), plus
     * the platform's own (scrubbed) text for platform_rejected / permission_missing, legacy rows included. A code with
     * no translation shows the stored platform text.
     */
    private static function logError(AdWriteAction $a): ?string
    {
        if (in_array($a->state, [AdWriteAction::SUCCEEDED, AdWriteAction::ROLLED_BACK], true)) {
            return null;
        }
        $code = (string) $a->error_code;
        if ($code !== '' && Lang::has('ads.errors.'.$code)) {
            $details = WriteActionController::storedDetails($a);

            return WriteDenied::withPlatformMessage(WriteDenied::messageFor($code, $details), $details['platform_message'] ?? null);
        }

        return $a->error_message !== null ? SecretScrubber::scrub($a->error_message) : null;
    }

    private function refused(WriteDenied $e, AdWriteAction $x): JsonResponse
    {
        return response()->json($e->body() + ['ok' => false, 'action_id' => $x->public_id], $e->status, $e->headers);
    }

    /**
     * The client's Idempotency-Key when it sends one; else a key derived from the request and a 10-second window, so a
     * double click replays while a deliberate repeat later is a new action.
     *
     * @param  array<string, mixed>  $data
     */
    private function legacyKey(Request $request, array $data): string
    {
        $header = (string) $request->header('Idempotency-Key', '');
        if ($header !== '') {
            if (! preg_match(WriteActionController::KEY_PATTERN, $header)) {
                throw new WriteDenied('validation_failed', 422, ['field' => 'Idempotency-Key']);
            }

            return $header;
        }

        return 'legacy:'.hash('sha256', implode('|', [$request->user()->id, $data['account_id'], $data['level'], $data['external_id'], $data['status'], (string) ($data['reason'] ?? '')]))
            .':'.intdiv(now()->getTimestamp(), 10);
    }
}
