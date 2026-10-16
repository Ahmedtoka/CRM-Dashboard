<?php

namespace App\Ads\Launch;

use App\Ads\Access\AdsScope;
use App\Models\AdLaunch;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * «جاهزة للنشر» (F4, kept small): the drafts content prepared that now wait for a buyer (buyer_review, or create_failed
 * which the buyer must retry or withdraw), counted per buyer. Content sees its own, a buyer their own queue, supervisors
 * and Ads authority everyone's. The lifecycle itself is unchanged (content → buyer → manager).
 */
final class LaunchHandoff
{
    public const STATES = [LaunchState::BuyerReview->value, LaunchState::CreateFailed->value];

    /**
     * @param  Builder<AdLaunch>  $q
     * @return Builder<AdLaunch>
     */
    public static function scope(Builder $q, User $u): Builder
    {
        $q = LaunchPolicy::visible($q, $u)->whereIn('ad_launches.state', self::STATES)->whereNotNull('ad_launches.reviewer_buyer_id');
        if ($u->isSupervisorOrAbove() || $u->hasAdsAuthority()) {
            return $q;
        }
        $buyer = app(AdsScope::class)->buyerFor($u);

        return $q->where(fn (Builder $w) => $w->where('ad_launches.prepared_by_id', $u->id)
            ->when($buyer !== null, fn (Builder $x) => $x->orWhere('ad_launches.reviewer_buyer_id', $buyer->id)));
    }

    /** The Today block: managers get the per-buyer list, everyone the total of their own view. @return array{total: int, buyers: list<array<string, mixed>>} */
    public static function summary(User $u): array
    {
        $buyers = $u->isSupervisorOrAbove() || $u->hasAdsAuthority() ? self::byBuyer($u) : [];

        return ['total' => $buyers !== [] ? array_sum(array_column($buyers, 'count')) : self::total($u), 'buyers' => $buyers];
    }

    public static function total(User $u): int
    {
        return self::scope(AdLaunch::query(), $u)->count();
    }

    /** @return list<array{buyer_id: int, buyer_name: string, count: int, oldest_at: ?string}> most waiting first */
    public static function byBuyer(User $u): array
    {
        $rows = self::scope(AdLaunch::query(), $u)
            ->selectRaw('ad_launches.reviewer_buyer_id as buyer_id, COUNT(*) as n, MIN(COALESCE(ad_launches.submitted_at, ad_launches.updated_at)) as oldest')
            ->groupBy('ad_launches.reviewer_buyer_id')->get();
        $names = MediaBuyer::query()->whereIn('id', $rows->pluck('buyer_id')->all())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'buyer_id' => (int) $r->buyer_id, 'buyer_name' => (string) ($names[$r->buyer_id] ?? '—'), 'count' => (int) $r->n,
            'oldest_at' => $r->oldest !== null ? Carbon::parse($r->oldest)->toIso8601String() : null,
        ])->sortBy([['count', 'desc'], ['oldest_at', 'asc']])->values()->all();
    }
}
