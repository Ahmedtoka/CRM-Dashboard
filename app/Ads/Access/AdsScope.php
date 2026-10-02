<?php

namespace App\Ads\Access;

use App\Enums\UserRole;
use App\Models\AdAccountAssignment;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;

final class AdsScope
{
    /**
     * null = every account (supervisor and above); a list = the accounts a media buyer held at any time in [from,to]
     * (whole history without a range); [] = nothing (content users, a buyer without a MediaBuyer link, anyone else).
     *
     * @return list<int>|null
     */
    public function accountIds(User $user, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): ?array
    {
        if ($user->isSupervisorOrAbove()) {
            return null;
        }

        $buyer = $this->buyerFor($user);
        if ($buyer === null) {
            return [];
        }

        $q = AdAccountAssignment::where('media_buyer_id', $buyer->id);
        if ($to !== null) {
            $q->where('starts_on', '<=', $to->toDateString());
        }
        if ($from !== null) {
            $q->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $from->toDateString()));
        }

        return $q->distinct()->pluck('ad_account_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function buyerFor(User $user): ?MediaBuyer
    {
        if ($user->role !== UserRole::MediaBuyer) {
            return null;
        }

        return MediaBuyer::where('user_id', $user->id)->first();
    }

    public function canSeeSpend(User $user): bool
    {
        return $user->role !== UserRole::Content;
    }
}
