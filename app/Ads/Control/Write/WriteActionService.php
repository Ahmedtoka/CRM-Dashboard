<?php

namespace App\Ads\Control\Write;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\Write\Types\SetStatusType;
use App\Models\AdAccount;
use App\Models\AdWriteAction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

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
    ) {}

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
