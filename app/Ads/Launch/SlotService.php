<?php

namespace App\Ads\Launch;

use App\Ads\Access\AdsScope;
use App\Ads\Audit\AdsAudit;
use App\Ads\Control\WritableAccounts;
use App\Ads\Control\Write\WriteDenied;
use App\Ads\Control\Write\WritePolicy;
use App\Enums\UserRole;
use App\Models\AdSet;
use App\Models\User;

/**
 * Open slots (spec 3.2): the ad sets content may target. Opened / closed by the buyer holding the account today or an
 * Ads-authority holder. A slot whose account has no buyer today is not offered (L 2.3).
 */
final class SlotService
{
    public function __construct(private readonly WritePolicy $policy, private readonly AdsScope $scope) {}

    public function canToggle(User $u, AdSet $s): bool
    {
        $account = $s->campaign?->account;
        if ($account === null || ! $account->is_active) {
            return false;
        }
        if ($u->hasAdsAuthority()) {
            return true;
        }

        return $u->role === UserRole::MediaBuyer && $this->policy->inScope($u, $account);
    }

    /** @throws WriteDenied 403 out_of_scope */
    public function toggle(User $u, AdSet $s, bool $open): AdSet
    {
        $s->loadMissing('campaign.account');
        if (! $this->canToggle($u, $s)) {
            throw WriteDenied::make('out_of_scope');
        }
        $was = $s->open_for_drafts_at !== null;
        $s->forceFill($open
            ? ['open_for_drafts_at' => $s->open_for_drafts_at ?? now(), 'open_for_drafts_by_id' => $u->id]
            : ['open_for_drafts_at' => null, 'open_for_drafts_by_id' => null])->save();
        if ($was !== $open) {
            AdsAudit::record($open ? 'launch.slot_opened' : 'launch.slot_closed', $s->campaign->account, null,
                ['ad_set_id' => $s->id, 'external_id' => $s->external_id, 'open' => $open], ['ad_set_name' => $s->name], $u);
        }

        return $s;
    }

    /** @return list<array{id:int, name:string, external_id:string, campaign:array{external_id:string, name:string, objective:?string}, account:array{id:int, name:string, platform:string}, buyer:array{id:int, name:string}}> */
    public function openSlots(): array
    {
        $out = [];
        foreach (AdSet::query()->openForDrafts()->with('campaign.account')->orderBy('name')->get() as $s) {
            $account = $s->campaign?->account;
            if ($account === null || ! $account->is_active || ! WritableAccounts::allows($account)) {
                continue;
            }
            $buyer = AccountBuyer::today($account);
            if ($buyer === null) {
                continue;
            }
            $out[] = [
                'id' => $s->id, 'name' => $s->name, 'external_id' => $s->external_id,
                'campaign' => ['external_id' => $s->campaign->external_id, 'name' => $s->campaign->name, 'objective' => $s->campaign->objective],
                'account' => ['id' => $account->id, 'name' => $account->name, 'platform' => $account->platform],
                'buyer' => ['id' => $buyer->id, 'name' => $buyer->name],
            ];
        }

        return $out;
    }

    /** @return list<array{id:int, name:string, status:?string, open:bool, opened_at:?string, campaign:array{name:string, status:?string}, account:array{id:int, name:string}}> */
    public function slotsFor(User $u): array
    {
        $ids = $u->hasAdsAuthority() ? null : ($this->scope->accountIds($u, now('Africa/Cairo')->toImmutable()->startOfDay(), now('Africa/Cairo')->toImmutable()->startOfDay()) ?? null);

        return AdSet::query()->with('campaign.account')
            ->whereHas('campaign', fn ($q) => $q->whereHas('account', fn ($a) => $a->where('is_active', true))
                ->when($ids !== null, fn ($q) => $q->whereIn('ad_account_id', $ids)))
            ->orderBy('name')->limit(300)->get()
            ->map(fn (AdSet $s) => [
                'id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'open' => $s->open_for_drafts_at !== null,
                'opened_at' => $s->open_for_drafts_at?->toIso8601String(),
                'campaign' => ['name' => $s->campaign->name, 'status' => $s->campaign->status],
                'account' => ['id' => $s->campaign->account->id, 'name' => $s->campaign->account->name],
            ])->values()->all();
    }
}
