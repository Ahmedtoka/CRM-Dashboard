<?php

namespace App\Policies;

use App\Models\SupportCase;
use App\Models\User;

/** A case follows its conversation: moderators only see and change their platforms' cases. */
class SupportCasePolicy
{
    public function view(User $user, SupportCase $case): bool
    {
        return $this->canAccess($user, $case);
    }

    public function update(User $user, SupportCase $case): bool
    {
        return $this->canAccess($user, $case);
    }

    private function canAccess(User $user, SupportCase $case): bool
    {
        $conversation = $case->conversation;

        return $conversation !== null && $user->canAccessPlatform($conversation->platform);
    }
}
