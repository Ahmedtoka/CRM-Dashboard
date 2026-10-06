<?php

namespace App\Ads\Launch;

use App\Ads\Access\AdsScope;
use App\Ads\Control\Write\WritePolicy;
use App\Ads\Materials\MaterialService;
use App\Ads\Reports\AdsFilter;
use App\Enums\UserRole;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what with a launch (L 5.1, D5 handled in ApproveLaunch). Content: own drafts; buyer: the launches of the
 * accounts held today; Ads authority: approve and everything a buyer does; supervisor without authority: prepare and see.
 */
final class LaunchPolicy
{
    public static function canPrepare(User $u): bool
    {
        return MaterialService::canAuthor($u) || MaterialService::canOperate($u);
    }

    public static function canEditDraft(User $u, AdLaunch $l): bool
    {
        return $l->prepared_by_id === $u->id || $u->isSupervisorOrAbove();
    }

    /** The buyer the launch waits for (holding the account today), or an Ads-authority holder. */
    public static function isReviewer(User $u, AdLaunch $l): bool
    {
        if ($u->hasAdsAuthority()) {
            return true;
        }
        $buyer = app(AdsScope::class)->buyerFor($u);
        $account = $l->account;

        return $buyer !== null && $account !== null && $l->reviewer_buyer_id === $buyer->id && app(WritePolicy::class)->inScope($u, $account);
    }

    public static function canApprove(User $u): bool
    {
        return $u->hasAdsAuthority();
    }

    /**
     * @param  Builder<AdLaunch>  $q
     * @return Builder<AdLaunch>
     */
    public static function visible(Builder $q, User $u): Builder
    {
        if ($u->isSupervisorOrAbove()) {
            return $q;
        }
        if ($u->role === UserRole::MediaBuyer) {
            $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->startOfDay();
            $ids = app(AdsScope::class)->accountIds($u, $today, $today) ?? [];

            return $q->where(fn (Builder $w) => $w->whereIn('ad_launches.ad_account_id', $ids)->orWhere('ad_launches.prepared_by_id', $u->id));
        }
        if ($u->role === UserRole::Content) {
            return $q->where(fn (Builder $w) => $w->where('ad_launches.prepared_by_id', $u->id)
                ->orWhereIn('ad_launches.ad_material_id', AdMaterial::query()->select('id')->where('created_by_id', $u->id)));
        }

        return $q->whereRaw('1 = 0');
    }

    public static function canSee(User $u, AdLaunch $l): bool
    {
        return self::visible(AdLaunch::query()->whereKey($l->id), $u)->exists();
    }
}
