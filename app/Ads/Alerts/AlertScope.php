<?php

namespace App\Ads\Alerts;

use App\Ads\Access\AdsScope;
use App\Ads\Reports\AdsFilter;
use App\Enums\UserRole;
use App\Models\AdsAlert;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Server-side policy of the decisions feed (spec 8). Out of scope = 404, never 403 (alerts spec S1.1). */
final class AlertScope
{
    /** Rules about the whole operation (owner level): never in a buyer's list. */
    public const OWNER_ONLY = ['msg.inbox_slow_for_ads'];

    /** Facts a buyer may only snooze; dismissing them is for Ads authority. */
    public const AUTHORITY_DISMISS = ['all.out_of_stock', 'all.product_unavailable', 'all.spend_spike_today'];

    public function __construct(private readonly AdsScope $ads) {}

    /** @return Builder<AdsAlert> */
    public function visible(User $u): Builder
    {
        $q = AdsAlert::query()->select('ads_alerts.*');
        if ($u->isSupervisorOrAbove() || $u->hasAdsAuthority()) {
            return $q;
        }
        if ($u->role !== UserRole::MediaBuyer) {
            return $q->whereRaw('1 = 0');
        }

        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();
        $ids = $this->ads->accountIds($u, $today, $today) ?? [];

        return $q->whereIn('ads_alerts.ad_account_id', $ids === [] ? [0] : $ids)->whereNotIn('ads_alerts.rule_id', self::OWNER_ONLY);
    }

    public function find(User $u, int $id): AdsAlert
    {
        return $this->visible($u)->whereKey($id)->firstOr(fn () => abort(404));
    }

    public function canDismiss(User $u, AdsAlert $a): bool
    {
        return ! in_array($a->rule_id, self::AUTHORITY_DISMISS, true) || $this->canManage($u);
    }

    public function canManage(User $u): bool
    {
        return $u->isAdmin() || $u->hasAdsAuthority();
    }

    /** @return Collection<int, User> */
    public function recipients(): Collection
    {
        return User::query()->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (User $u) => $u->isSupervisorOrAbove() || $u->role === UserRole::MediaBuyer)->values();
    }
}
