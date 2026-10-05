<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Control\Write\WritePolicy;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\AdWriteStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The Phase B write pipeline over HTTP: propose, show (and, from task 9, confirm / cancel / rollback). Every refusal is a
 * WriteDenied rendered in the stable shape {code, message, details, errors.status}.
 */
class WriteActionController extends Controller
{
    public const KEY_PATTERN = '/^[A-Za-z0-9:_-]{8,100}$/';

    public function store(Request $request, WriteActionService $service): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $data = $this->validated($request, [
            'type' => ['required', Rule::in([SetStatusType::TYPE])],
            'account_id' => ['required', 'integer', Rule::exists('ad_accounts', 'id')],
            'target' => ['required', 'array'],
            'target.level' => ['required', Rule::in(WritePolicy::LEVELS)],
            'target.external_id' => ['required', 'string', 'max:100'],
            'params' => ['required', 'array'],
            'params.to' => ['required', Rule::in(['active', 'paused'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $service->propose($request->user(), AdAccount::findOrFail($data['account_id']), $data['target']['level'],
            (string) $data['target']['external_id'], $data['params']['to'], $data['reason'] ?? null, $key);

        return $this->proposal($result['action'], $result['replayed']);
    }

    public function show(Request $request, string $action, WriteActionService $service): JsonResponse
    {
        $x = $service->find($request->user(), $action);

        return response()->json([
            'action' => self::present($x),
            'diff' => $x->diff,
            'diff_hash' => $x->diff_hash,
            'notes' => $x->notes ?? [],
            'limits_checked' => array_values($x->limits_checked ?? []),
            'steps' => $x->steps->map(fn (AdWriteStep $s) => [
                'seq' => $s->seq, 'op' => $s->op, 'state' => $s->state, 'before' => $s->before, 'after' => $s->after,
                'request_sent_at' => $s->request_sent_at?->toIso8601String(), 'error_code' => $s->error_code, 'error_message' => $s->error_message,
                'meta' => $s->meta,
            ])->values()->all(),
            'timeline' => AdsAuditLog::query()->where('subject_type', 'AdWriteAction')->where('subject_id', $x->id)->orderBy('id')->get()
                ->map(fn (AdsAuditLog $r) => [
                    'at' => $r->at?->toIso8601String(), 'action' => $r->action, 'actor_type' => $r->actor_type, 'actor_user_id' => $r->actor_user_id,
                    'before' => $r->before, 'after' => $r->after,
                ])->values()->all(),
        ]);
    }

    /** Confirm a proposal: claim, execute inline, answer with the action's end state. */
    public function confirm(Request $request, string $action, WriteActionService $service): JsonResponse
    {
        $x = $service->find($request->user(), $action);
        $data = $this->validated($request, ['diff_hash' => ['required', 'string', 'size:64']]);

        return self::outcome($service->confirm($request->user(), $x, $data['diff_hash']));
    }

    /** Propose the inverse of a finished action (a new proposal, confirmed like any other). */
    public function rollback(Request $request, string $action, WriteActionService $service): JsonResponse
    {
        $x = $service->find($request->user(), $action);
        $key = $this->idempotencyKey($request);
        $data = $this->validated($request, ['reason' => ['nullable', 'string', 'max:1000']]);

        $result = $service->rollback($request->user(), $x, $key, $data['reason'] ?? null);

        return $this->proposal($result['action'], $result['replayed']);
    }

    public function cancel(Request $request, string $action, WriteActionService $service): JsonResponse
    {
        $x = $service->cancel($request->user(), $service->find($request->user(), $action));

        return response()->json(['action' => self::present($x)]);
    }

    /**
     * HTTP answer for an action after an attempt: succeeded 200; failed 422 (or the code's own status, 429 for
     * rate_limited); superseded 409 precondition_failed; unknown or executing (retry scheduled) 202.
     */
    public static function outcome(AdWriteAction $x): JsonResponse
    {
        $present = self::present($x);

        return match ($x->state) {
            AdWriteAction::SUCCEEDED => response()->json(['action' => $present, 'message' => __(match (true) {
                $x->isStop() => 'ads.flash.stopped',
                (bool) ($x->outcome['noop'] ?? false) => 'ads.write.notes.already_active',
                default => 'ads.flash.resumed',
            })]),
            AdWriteAction::UNKNOWN => response()->json(['action' => $present, 'message' => __('ads.errors.unknown_outcome')], 202),
            AdWriteAction::EXECUTING => response()->json(['action' => $present, 'message' => __('ads.errors.stop_retrying')], 202),
            AdWriteAction::SUPERSEDED, AdWriteAction::SUPERSEDED_BY_STOP => self::refusal(WriteDenied::make('precondition_failed', ['state' => $x->state]), $present),
            default => self::refusal(self::failure($x), $present),
        };
    }

    public static function failure(AdWriteAction $x): WriteDenied
    {
        $code = (string) ($x->error_code ?: 'failed');
        $details = array_filter([
            'platform_message' => in_array($code, ['platform_rejected', 'permission_missing'], true) ? $x->error_message : null,
            'deep_link' => $x->outcome['deep_link'] ?? null,
        ], fn ($v) => $v !== null);
        $retry = $x->outcome['retry_after'] ?? null;

        return WriteDenied::make($code, $details, $code === 'rate_limited' && $retry !== null ? ['Retry-After' => (string) $retry] : []);
    }

    /** @param  array<string, mixed>  $present */
    private static function refusal(WriteDenied $e, array $present): JsonResponse
    {
        return response()->json($e->body() + ['action' => $present], $e->status, $e->headers);
    }

    /** @return array<string, mixed> */
    public static function present(AdWriteAction $x): array
    {
        return [
            'id' => $x->public_id,
            'state' => $x->state,
            'type' => $x->type,
            'level' => $x->target_level,
            'external_id' => $x->target_external_id,
            'name' => $x->target_name,
            'account' => $x->account_name,
            'account_id' => $x->ad_account_id,
            'to' => $x->to_status,
            'from_status' => $x->from_status,
            'expires_at' => $x->expires_at?->toIso8601String(),
            'source' => $x->source,
            'attempts' => (int) $x->attempts,
            'error_code' => $x->error_code,
            'error_message' => $x->error_message,
            'outcome' => $x->outcome,
            'rollback_of' => $x->rollback_of_id !== null ? $x->loadMissing('rollbackOf:id,public_id')->rollbackOf?->public_id : null,
        ];
    }

    private function proposal(AdWriteAction $x, bool $replayed): JsonResponse
    {
        $body = [
            'action' => self::present($x),
            'diff' => $x->diff,
            'diff_hash' => $x->diff_hash,
            'notes' => $x->notes ?? [],
            'limits_checked' => array_values($x->limits_checked ?? []),
        ];

        return $replayed
            ? response()->json($body, 200, ['Idempotent-Replayed' => 'true'])
            : response()->json($body, 201);
    }

    /** @throws WriteDenied 422 validation_failed */
    private function idempotencyKey(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match(self::KEY_PATTERN, $key)) {
            throw new WriteDenied('validation_failed', 422, ['field' => 'Idempotency-Key']);
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws WriteDenied 422 validation_failed
     */
    private function validated(Request $request, array $rules): array
    {
        $v = Validator::make($request->all(), $rules);
        if ($v->fails()) {
            throw new WriteDenied('validation_failed', 422, ['fields' => array_keys($v->errors()->toArray())]);
        }

        return $v->validated();
    }
}
