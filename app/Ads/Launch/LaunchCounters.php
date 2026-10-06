<?php

namespace App\Ads\Launch;

use App\Ads\Access\AdsScope;
use App\Models\AdLaunch;
use App\Models\User;

/** Sidebar counters (spec 3.5): content «رجعتلك n», buyer «مستنية مراجعتك n», manager «مستنية موافقتك n». Three indexed counts. */
final class LaunchCounters
{
    /** @return array{content_returned: int, buyer_review: int, awaiting_approval: int} */
    public static function for(User $u): array
    {
        $buyer = app(AdsScope::class)->buyerFor($u);

        return [
            'content_returned' => AdLaunch::query()->where('prepared_by_id', $u->id)->where('state', LaunchState::ChangesRequested->value)->count(),
            'buyer_review' => $buyer === null ? 0 : AdLaunch::query()->where('reviewer_buyer_id', $buyer->id)
                ->whereIn('state', [LaunchState::BuyerReview->value, LaunchState::CreateFailed->value])->count(),
            'awaiting_approval' => $u->hasAdsAuthority() ? AdLaunch::query()->where('state', LaunchState::AwaitingApproval->value)->count() : 0,
        ];
    }
}
