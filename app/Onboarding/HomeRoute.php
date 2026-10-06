<?php

namespace App\Onboarding;

use App\Enums\UserRole;
use App\Models\User;

/** Where a signed-in user lands: «ابدأ من هنا» for a fresh admin with nothing connected, the inbox otherwise. */
final class HomeRoute
{
    public static function for(?User $user): string
    {
        if ($user?->isAdsRole()) {
            return $user->role === UserRole::Content
                ? route('ads.materials.index', absolute: false)
                : route('ads.today', absolute: false);
        }

        return app(OnboardingProgress::class)->shouldRedirect($user)
            ? route('onboarding.index', absolute: false)
            : route('inbox', absolute: false);
    }
}
