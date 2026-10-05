<?php

namespace App\Ads\Control;

use App\Ads\Control\Write\WritePolicy;
use App\Models\AdAccount;
use App\Models\User;

/**
 * Facade over WritePolicy for the pages (can_write flags, levels) and the own-status rule. Every platform write goes
 * through the Phase B pipeline (WriteActionService); the slice-1 setStatus path was removed with the B5 shim.
 *
 * Status rule: an ad's OWN status decides Stop / Run, normalised across platforms (ACTIVE/ENABLE = active,
 * PAUSED/DISABLE = paused); effective_status (which folds in the parents) is never used.
 */
final class AdWriteService
{
    public const LEVELS = ['campaign', 'adset', 'ad'];

    public const STATUSES = ['active', 'paused'];

    /** Platform spellings of a running / paused own status (Meta ACTIVE/PAUSED, TikTok ENABLE/DISABLE). */
    public const ACTIVE_STATUSES = ['ACTIVE', 'ENABLE'];

    public const PAUSED_STATUSES = ['PAUSED', 'DISABLE'];

    public function __construct(private readonly WritePolicy $policy) {}

    /** @return 'active'|'paused'|null null for anything else (archived, deleted, in review, unknown) */
    public static function statusKind(?string $status): ?string
    {
        $s = strtoupper((string) $status);

        return in_array($s, self::ACTIVE_STATUSES, true) ? 'active' : (in_array($s, self::PAUSED_STATUSES, true) ? 'paused' : null);
    }

    /** Admin/supervisor: any active account; media buyer: only the accounts assigned to their buyer today; nobody else. */
    public function canWrite(User $u, AdAccount $a): bool
    {
        return $this->canWriteMany($u, [$a])[$a->id];
    }

    /** Scope only: is this account one the user may act on (ignores the account's write switch). Delegates to WritePolicy. */
    public function inScope(User $u, AdAccount $a): bool
    {
        return $this->policy->inScope($u, $a);
    }

    /**
     * canWrite for many accounts with the buyer's assignments for today read once (WritePolicy). The rows must carry
     * is_active and write_enabled.
     *
     * @param  iterable<AdAccount>  $accounts
     * @return array<int, bool> account id => allowed
     */
    public function canWriteMany(User $u, iterable $accounts): array
    {
        return $this->policy->canWriteMany($u, $accounts);
    }

    /**
     * Levels the user may Run / Stop at (B2, D4), from WritePolicy: Ads authority holders every level, everyone else ad
     * level only.
     *
     * @return list<'campaign'|'adset'|'ad'>
     */
    public function allowedLevels(User $u): array
    {
        return $this->policy->allowedLevels($u);
    }
}
