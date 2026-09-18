<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->canAccessPlatform($conversation->platform);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $user->canAccessPlatform($conversation->platform);
    }

    /** Wiping a conversation's history (test resets) is destructive: supervisors and admins only. */
    public function reset(User $user, Conversation $conversation): bool
    {
        return $user->isSupervisorOrAbove();
    }
}
