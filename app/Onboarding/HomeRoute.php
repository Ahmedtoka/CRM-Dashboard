<?php

namespace App\Onboarding;

use App\Enums\UserRole;
use App\Models\User;

/** Where a signed-in user lands: «ابدأ من هنا» for a fresh admin, «النهارده» for admins and supervisors, the inbox for agents, the Ads Hub for the ads roles. */
final class HomeRoute
{
    public static function for(?User $user): string
    {
        if ($user?->isAdsRole()) {
            return $user->role === UserRole::Content
                ? route('ads.materials.index', absolute: false)
                : route('ads.today', absolute: false);
        }

        if (app(OnboardingProgress::class)->shouldRedirect($user)) {
            return route('onboarding.index', absolute: false);
        }

        // Control room S4: admins and supervisors start on «النهارده»; agents keep the inbox.
        return $user?->isSupervisorOrAbove() ? route('today', absolute: false) : route('inbox', absolute: false);
    }
}
