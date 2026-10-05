<?php

namespace App\Ads\Control\Write;

use App\Ads\Access\AdsScope;
use App\Ads\Control\WritableAccounts;
use App\Ads\Reports\AdsFilter;
use App\Models\AdAccount;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The one place that decides who may write what (B2, write-api 2.5). Evaluated at propose, confirm and execute with the
 * same rules each time; the phase only goes into the refusal details. Order: scope, level (Ads authority, D4), platform,
 * write_enabled, connection, kill switch. Stop is exempt from the kill switch only (2.1 rule 5); it is still subject to
 * scope, the level rule, the account write switch and the connection state.
 */
final class WritePolicy
{
    public const LEVELS = ['campaign', 'adset', 'ad'];

    public function __construct(private readonly AdsScope $scope) {}

    /**
     * @param  'campaign'|'adset'|'ad'  $level
     * @param  'active'|'paused'  $to
     * @param  'propose'|'confirm'|'execute'  $phase
     *
     * @throws WriteDenied
     */
    public function authorize(User $u, AdAccount $a, string $level, string $to, string $phase): void
    {
        $details = ['phase' => $phase];

        if (! $this->inScope($u, $a)) {
            throw WriteDenied::make('out_of_scope', $details);
        }
        if (! in_array($level, $this->allowedLevels($u), true)) {
            throw WriteDenied::make('ads_authority_required', $details + ['level' => $level]);
        }
        if ($a->platform === 'google') {
            throw WriteDenied::make('platform_not_writable', $details);
        }
        if (! WritableAccounts::allows($a)) {
            throw WriteDenied::make('account_not_writable', $details);
        }
        $connection = $a->connection;
        if ($connection?->status === 'disabled') {
            throw WriteDenied::make('connection_disabled', $details);
        }
        if ($connection?->status === 'needs_reconnect') {
            throw WriteDenied::make('connection_needs_reconnect', $details);
        }
        if ($connection?->read_only) {
            throw WriteDenied::make('connection_read_only', $details);
        }
        if (! WriteSwitch::allows('set_status', $to)) {
            throw WriteDenied::make(WriteSwitch::CODE, $details);
        }
    }

    /** Scope only: an active account; supervisor and above any account; a buyer today's assignments; nobody else. */
    public function inScope(User $u, AdAccount $a): bool
    {
        $today = $this->todayIds($u);

        return (bool) $a->is_active && ($today === null || in_array($a->id, $today, true));
    }

    /**
     * Levels the user may Run / Stop at (D4): Ads authority holders every level, everyone else ad level only.
     *
     * @return list<'campaign'|'adset'|'ad'>
     */
    public function allowedLevels(User $u): array
    {
        return $u->hasAdsAuthority() ? self::LEVELS : ['ad'];
    }

    /**
     * Scope and the account write switch for many accounts, with the buyer's assignments for today read once. The rows
     * must carry is_active and write_enabled.
     *
     * @param  iterable<AdAccount>  $accounts
     * @return array<int, bool> account id => allowed
     */
    public function canWriteMany(User $u, iterable $accounts): array
    {
        $today = $this->todayIds($u);
        $out = [];
        foreach ($accounts as $a) {
            $out[$a->id] = ($today === null || in_array($a->id, $today, true)) && WritableAccounts::allows($a);
        }

        return $out;
    }

    /**
     * Accounts whose write actions the user may see (same scope as every Ads report).
     *
     * @return list<int>|null null = every account
     */
    public function visibleAccountIds(User $u): ?array
    {
        return $this->scope->accountIds($u);
    }

    /** @return list<int>|null null = every account */
    private function todayIds(User $u): ?array
    {
        if ($u->isSupervisorOrAbove()) {
            return null;
        }
        if ($this->scope->buyerFor($u) === null) {
            return [];
        }

        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();

        return $this->scope->accountIds($u, $today, $today) ?? [];
    }
}
