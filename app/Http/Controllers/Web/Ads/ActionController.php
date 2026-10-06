<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Control\AdWriteService;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/** The legacy one-shot Stop / Run endpoint. The old Actions page is «محتاج قرار» › السجل now (/ads/decisions?tab=log). */
class ActionController extends Controller
{
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
