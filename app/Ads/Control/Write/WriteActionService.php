<?php

namespace App\Ads\Control\Write;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\Write\Types\SetStatusType;
use App\Ads\Platforms\Data\ObjectState;
use App\Ads\Reports\AdsFilter;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The write pipeline's front door (B2, write-api 5): propose, then confirm. A proposal never takes the business key and
 * never calls the platform (2.1 rule 1). Every mark that decides a write lives in ad_write_actions (unique keys and
 * compare-and-set on state), never in the cache (2.1 rule 8).
 */
class WriteActionService
{
    public function __construct(
        private readonly WritePolicy $policy,
        private readonly SetStatusType $type,
        private readonly RunGuard $runGuard,
        private readonly WriteExecutor $executor,
        private readonly WriteLimits $limits,
    ) {}

    /**
     * Run states that count as an activation: confirmed and sent (or a noop). A refused or failed Run never counts.
     * Rolled-back and Stop-superseded Runs were executed too, so they keep counting.
     */
    private const ACTIVATION_STATES = [
        AdWriteAction::EXECUTING, AdWriteAction::SUCCEEDED, AdWriteAction::UNKNOWN, AdWriteAction::ROLLED_BACK, AdWriteAction::SUPERSEDED_BY_STOP,
    ];

    /**
     * @param  'campaign'|'adset'|'ad'  $level
     * @param  'active'|'paused'  $to
     * @return array{action: AdWriteAction, replayed: bool}
     *
     * @throws WriteDenied
     */
    public function propose(User $u, AdAccount $a, string $level, string $externalId, string $to, ?string $reason, string $key,
        string $source = 'ui', ?string $sourceRef = null, string $actorType = 'user', ?AdWriteAction $rollbackOf = null): array
    {
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $requestHash = Canonical::hash([
            'type' => SetStatusType::TYPE, 'account_id' => $a->id, 'level' => $level, 'external_id' => $externalId, 'to' => $to, 'reason' => $reason,
        ]);

        $existing = $this->byKey($u, $key);
        if ($existing !== null) {
            return $this->replay($existing, $requestHash);
        }

        try {
            $this->policy->authorize($u, $a, $level, $to, 'propose');
            $target = $this->type->target($a, $level, $externalId);
            [$live, $limits, $notes] = $to === 'active' ? $this->runGuard->atPropose($u, $a, $target) : [null, [], []];
        } catch (WriteDenied $e) {
            // No action row, but a refused attempt stays visible (slice-1 F-003 C23 intent).
            AdsAudit::record('write.refused', $a, null, null, [
                'code' => $e->errorCode, 'level' => $level, 'external_id' => $externalId, 'to' => $to, 'source' => $source, 'phase' => 'propose',
            ], $u);

            throw $e;
        }

        $built = $this->type->build($a, $target, $to, $live, $limits, $notes);
        $request = app()->bound('request') ? request() : null;

        try {
            $x = AdWriteAction::create([
                'type' => SetStatusType::TYPE,
                'state' => AdWriteAction::PROPOSED,
                'platform' => $a->platform,
                'ad_account_id' => $a->id,
                'account_name' => $a->name,
                'target_level' => $level,
                'target_external_id' => $externalId,
                'target_name' => mb_substr((string) $target->name, 0, 500),
                'target_key' => $built['target_key'],
                'from_status' => $built['from_status'],
                'to_status' => $to,
                'params' => $built['params'],
                'diff' => $built['diff'],
                'diff_hash' => $built['diff_hash'],
                'expected' => $built['expected'],
                'limits_checked' => $built['limits_checked'],
                'notes' => $built['notes'],
                'reason' => $reason,
                'source' => $source,
                'source_ref' => $sourceRef,
                'actor_type' => $actorType,
                'proposed_by_id' => $u->id,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'open_business_key' => null, // never at proposal (2.1 rule 1)
                'expires_at' => now()->addMinutes(max(1, (int) config('crm.ads.write.proposal_ttl_minutes', 10))),
                'rollback_of_id' => $rollbackOf?->id,
                'request_id' => $request?->headers->get('X-Request-Id') ?? $request?->attributes->get('request_id'),
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent() !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A parallel twin with the same key won the insert: answer like a replay.
            $twin = $this->byKey($u, $key);
            if ($twin === null) {
                throw $e;
            }

            return $this->replay($twin, $requestHash);
        }

        AdsAudit::record('write.proposed', $x, null, ['state' => $x->state, 'to' => $to, 'level' => $level, 'external_id' => $externalId],
            ['public_id' => $x->public_id, 'diff_hash' => $x->diff_hash, 'source' => $source], $u);

        return ['action' => $x, 'replayed' => false];
    }

    /**
     * Step 2 of two: claim the proposal (DB compare-and-set), take the Run key (a Stop never takes one), supersede the
     * other proposed Runs on the target, then execute inline. Exactly one platform call per confirmed intent.
     *
     * @throws WriteDenied
     */
    public function confirm(User $u, AdWriteAction $x, string $diffHash): AdWriteAction
    {
        if ($x->proposed_by_id !== $u->id) {
            throw WriteDenied::make('not_proposer');
        }
        $x = $this->expireIfDue($x);
        if ($x->state === AdWriteAction::EXPIRED) {
            throw WriteDenied::make('proposal_expired');
        }
        if ($x->state !== AdWriteAction::PROPOSED) {
            throw WriteDenied::make('not_confirmable', ['state' => $x->state]);
        }
        if (! hash_equals((string) $x->diff_hash, $diffHash)) {
            throw WriteDenied::make('diff_changed');
        }

        $account = $x->account;
        try {
            if ($account === null) {
                throw WriteDenied::make('not_found');
            }
            // Abilities are re-evaluated now, never taken from the proposal.
            $this->policy->authorize($u, $account, $x->target_level, (string) $x->to_status, 'confirm');
        } catch (WriteDenied $e) {
            AdsAudit::record('write.refused', $x, null, null, ['code' => $e->errorCode, 'public_id' => $x->public_id, 'phase' => 'confirm'], $u);

            throw $e;
        }

        $noop = false;
        if (! $x->isStop()) {
            $stop = AdWriteAction::where('target_key', $x->target_key)->where('to_status', 'paused')
                ->whereIn('state', AdWriteAction::OPEN)->first(['id', 'public_id']);
            if ($stop !== null) {
                throw WriteDenied::make('stop_in_progress', ['action_id' => $stop->public_id]);
            }
            try {
                $guard = $this->runGuard->atConfirm($u, $x);
            } catch (WriteDenied $e) {
                $this->refuseProposed($u, $x, $e);

                throw $e;
            }
            $noop = $guard['noop'];
            $this->recordConfirmRead($x, $guard['read'], $guard['limits_checked']);
        }

        $superseded = $this->claim($u, $x);

        AdsAudit::record('write.confirmed', $x, ['state' => AdWriteAction::PROPOSED], ['state' => AdWriteAction::EXECUTING],
            ['public_id' => $x->public_id, 'open_business_key' => $x->open_business_key], $u);
        foreach ($superseded as $other) {
            AdsAudit::record('write.superseded', $other, ['state' => AdWriteAction::PROPOSED], ['state' => AdWriteAction::SUPERSEDED],
                ['public_id' => $other->public_id, 'superseded_by' => $x->public_id], $u);
        }

        return $this->executor->execute($x, $noop);
    }

    /**
     * A Run-guard refusal at confirm ends the proposal (CAS on proposed): superseded when the platform no longer holds
     * the expected state (precondition_failed), failed otherwise.
     */
    private function refuseProposed(User $u, AdWriteAction $x, WriteDenied $e): void
    {
        $state = $e->errorCode === 'precondition_failed' ? AdWriteAction::SUPERSEDED : AdWriteAction::FAILED;
        $changed = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::PROPOSED)->update([
            'state' => $state, 'error_code' => $e->errorCode, 'finished_at' => now(), 'updated_at' => now(),
        ]) === 1;
        if ($changed) {
            AdsAudit::record('write.'.$state, $x, ['state' => AdWriteAction::PROPOSED], ['state' => $state],
                array_filter(['public_id' => $x->public_id, 'error_code' => $e->errorCode, 'phase' => 'confirm', 'details' => $e->details ?: null]), $u);
        }
    }

    /**
     * Keeps what the confirm-time guard saw: the new live read (if one was made) and the re-evaluated limits. A query
     * update guarded by state = proposed, so a losing double-submit never overwrites the winner's values.
     */
    private function recordConfirmRead(AdWriteAction $x, ?ObjectState $read, ?array $limits): void
    {
        $values = [];
        if ($read !== null) {
            $values['expected'] = array_merge($x->expected ?? [], ['confirm_read' => ['read_at' => now()->toIso8601String(), 'live' => $read->toArray()]]);
        }
        if ($limits !== null) {
            $values['limits_checked'] = $limits;
        }
        if ($values === []) {
            return;
        }
        $written = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::PROPOSED)
            ->update(array_map(fn ($v) => json_encode($v), $values) + ['updated_at' => now()]) === 1;
        if ($written) {
            // In memory too, so the claim appends the activation caps to these rows; never saved from the model.
            $x->forceFill($values)->syncOriginalAttributes(array_keys($values));
        }
    }

    /**
     * Rollback = propose the inverse (write-api 6). The inverse is a new action with its own rules: undoing a Stop is a
     * Run (policy, kill switch, Run guard), undoing a Run is a Stop (always allowed in scope).
     *
     * @return array{action: AdWriteAction, replayed: bool}
     *
     * @throws WriteDenied 422 not_reversible, or any propose refusal
     */
    public function rollback(User $u, AdWriteAction $done, string $key, ?string $reason = null): array
    {
        $inverse = $this->type->inverse($done);
        $account = $done->account;
        if ($inverse === null || $account === null) {
            throw WriteDenied::make('not_reversible', ['state' => $done->state]);
        }

        return $this->propose($u, $account, $done->target_level, $done->target_external_id, $inverse['to'], $reason, $key,
            source: 'rollback', sourceRef: $done->public_id, rollbackOf: $done);
    }

    /** proposed → cancelled by the proposer (CAS). */
    public function cancel(User $u, AdWriteAction $x): AdWriteAction
    {
        if ($x->proposed_by_id !== $u->id) {
            throw WriteDenied::make('not_proposer');
        }
        $x = $this->expireIfDue($x);
        $changed = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::PROPOSED)
            ->update(['state' => AdWriteAction::CANCELLED, 'finished_at' => now(), 'updated_at' => now()]) === 1;
        if (! $changed) {
            throw WriteDenied::make('not_confirmable', ['state' => $x->fresh()->state]);
        }
        $x->refresh();
        AdsAudit::record('write.cancelled', $x, ['state' => AdWriteAction::PROPOSED], ['state' => AdWriteAction::CANCELLED], ['public_id' => $x->public_id], $u);

        return $x;
    }

    /** SQLSTATE 40001 (serialization failure) or MySQL/MariaDB error 1213 (deadlock). */
    public static function isDeadlock(QueryException|DeadlockException $e): bool
    {
        if ($e instanceof DeadlockException) {
            return true; // Laravel's own concurrency-error wrapper inside a nested transaction
        }

        return (string) ($e->errorInfo[0] ?? '') === '40001' || (int) ($e->errorInfo[1] ?? 0) === 1213;
    }

    /**
     * The claim, one transaction: CAS proposed → executing with the Run key, then supersede the proposed Runs on the
     * target (a proposed Stop is never superseded, 2.1 rule 3).
     *
     * @return list<AdWriteAction> the actions superseded by this confirm
     *
     * @throws WriteDenied 409 not_confirmable / action_in_progress, 410 proposal_expired
     */
    private function claim(User $u, AdWriteAction $x): array
    {
        $key = $x->isStop() ? null : SetStatusType::runKey($x->target_key);

        try {
            $ids = DB::transaction(function () use ($u, $x, $key) {
                $now = now();
                $update = [
                    'state' => AdWriteAction::EXECUTING,
                    'confirmed_by_id' => $u->id,
                    'confirmed_at' => $now,
                    'confirmed_role' => $u->role?->value ?? (string) $u->role,
                    'executing_at' => $now,
                    'open_business_key' => $key,
                    'updated_at' => $now,
                ];
                if (! $x->isStop()) {
                    $update['limits_checked'] = json_encode(array_merge(array_values($x->limits_checked ?? []), $this->activationCaps($u, $x)));
                }
                $claimed = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::PROPOSED)->where('expires_at', '>', $now)->update($update) === 1;
                if (! $claimed) {
                    return null;
                }

                $ids = AdWriteAction::where('target_key', $x->target_key)->where('id', '<>', $x->id)
                    ->where('state', AdWriteAction::PROPOSED)->where('to_status', 'active')->pluck('id')->all();
                if ($ids !== []) {
                    AdWriteAction::whereIn('id', $ids)->where('state', AdWriteAction::PROPOSED)->update([
                        'state' => AdWriteAction::SUPERSEDED, 'superseded_by_id' => $x->id, 'finished_at' => $now, 'updated_at' => $now,
                    ]);
                }

                return $ids;
            });
        } catch (WriteDenied $e) {
            // An activation cap is reached: the transaction rolled back; the proposal ends failed (CAS, outside it).
            $this->refuseProposed($u, $x, $e);

            throw $e;
        } catch (UniqueConstraintViolationException) {
            // Another Run holds the key on this target: the transaction rolled back, this action stays proposed.
            $holder = AdWriteAction::where('open_business_key', $key)->value('public_id');

            throw WriteDenied::make('action_in_progress', array_filter(['action_id' => $holder]));
        } catch (QueryException|DeadlockException $e) {
            // MariaDB may pick this claim as a deadlock victim when two Runs race on the same target (both lock the
            // account row and the unique key): the transaction rolled back, the action stays proposed.
            if (! self::isDeadlock($e)) {
                throw $e;
            }

            throw WriteDenied::make('action_in_progress', ['reason' => 'deadlock']);
        }

        if ($ids === null) {
            $x->refresh();
            $x = $this->expireIfDue($x);

            throw $x->state === AdWriteAction::EXPIRED
                ? WriteDenied::make('proposal_expired')
                : WriteDenied::make('not_confirmable', ['state' => $x->state]);
        }
        $x->refresh();

        return AdWriteAction::whereIn('id', $ids)->get()->all();
    }

    /**
     * Activation caps (B3), Run only, inside the claim transaction: the account row and then the confirming user's row
     * are locked (MariaDB row locks, so two confirms on one account or by one user count one after the other; SQLite
     * ignores them), then the Runs confirmed since the
     * start of today in Cairo are counted per confirming user and per account.
     *
     * @return list<array{key: string, limit: int, requested: int, outcome: string}> the evaluated caps
     *
     * @throws WriteDenied 422 cap_exceeded {key, limit, used}
     */
    private function activationCaps(User $u, AdWriteAction $x): array
    {
        // Always the account row first, then the user row (one lock order, so two confirms never deadlock on each other).
        $account = AdAccount::whereKey($x->ad_account_id)->lockForUpdate()->first();
        User::whereKey($u->id)->lockForUpdate()->first(['id']);
        $limits = $this->limits->for($u, $account);
        $since = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay()->utc();
        $count = fn (string $column, int $value) => AdWriteAction::where('type', SetStatusType::TYPE)->where('to_status', 'active')
            ->whereIn('state', self::ACTIVATION_STATES)->where('confirmed_at', '>=', $since)->where($column, $value)->count();

        $rows = [];
        foreach (['activations_per_user_day' => ['confirmed_by_id', $u->id], 'activations_per_account_day' => ['ad_account_id', (int) $x->ad_account_id]] as $key => [$column, $value]) {
            $used = $count($column, $value);
            $limit = $limits[$key];
            if ($used >= $limit) {
                throw WriteDenied::make('cap_exceeded', ['key' => $key, 'limit' => $limit, 'used' => $used]);
            }
            $rows[] = ['key' => $key, 'limit' => $limit, 'requested' => $used + 1, 'outcome' => 'ok'];
        }

        return $rows;
    }

    /**
     * The action as the user may see it: the proposer, or anyone whose Ads scope covers the account. A proposed row past
     * its TTL is turned into expired here (lazy expiry, CAS on state).
     *
     * @throws WriteDenied 404 not_found
     */
    public function find(User $u, string $publicId): AdWriteAction
    {
        $x = AdWriteAction::where('public_id', $publicId)->first();
        if ($x === null || ! $this->canSee($u, $x)) {
            throw WriteDenied::make('not_found');
        }

        return $this->expireIfDue($x);
    }

    public function canSee(User $u, AdWriteAction $x): bool
    {
        if ($x->proposed_by_id === $u->id) {
            return true;
        }
        $visible = $this->policy->visibleAccountIds($u);

        return $visible === null || in_array((int) $x->ad_account_id, $visible, true);
    }

    /** proposed + past expires_at → expired (CAS); only the call that makes the transition audits it. */
    public function expireIfDue(AdWriteAction $x): AdWriteAction
    {
        if ($x->state !== AdWriteAction::PROPOSED || $x->expires_at === null || $x->expires_at->isFuture()) {
            return $x;
        }
        $changed = AdWriteAction::whereKey($x->id)->where('state', AdWriteAction::PROPOSED)
            ->update(['state' => AdWriteAction::EXPIRED, 'finished_at' => now(), 'updated_at' => now()]) === 1;
        $x->refresh();
        if ($changed) {
            AdsAudit::record('write.expired', $x, ['state' => AdWriteAction::PROPOSED], ['state' => AdWriteAction::EXPIRED], ['public_id' => $x->public_id]);
        }

        return $x;
    }

    private function byKey(User $u, string $key): ?AdWriteAction
    {
        return AdWriteAction::where('proposed_by_id', $u->id)->where('idempotency_key', $key)->first();
    }

    /** @return array{action: AdWriteAction, replayed: bool} */
    private function replay(AdWriteAction $existing, string $requestHash): array
    {
        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            throw WriteDenied::make('idempotency_key_reused', ['action_id' => $existing->public_id]);
        }

        return ['action' => $this->expireIfDue($existing), 'replayed' => true];
    }
}
