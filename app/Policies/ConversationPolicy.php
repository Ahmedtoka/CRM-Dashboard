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
}
