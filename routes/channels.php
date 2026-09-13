<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Shared inbox feed: any active user.
Broadcast::channel('inbox', fn (User $user) => (bool) $user->is_active);

// Comments screen feed: any active user.
Broadcast::channel('comments', fn (User $user) => (bool) $user->is_active);

// Presence per conversation: users allowed on the conversation's platform.
Broadcast::channel('conversation.{conversation}', function (User $user, Conversation $conversation) {
    if (! $user->is_active || ! $user->canAccessPlatform($conversation->platform)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'color' => $user->color];
});

// Integration progress (Shopify import): active admins only.
Broadcast::channel('integrations', fn (User $user) => (bool) $user->is_active && $user->isAdmin());

// Personal notifications: own id only.
Broadcast::channel('user.{id}', fn (User $user, $id) => (int) $user->id === (int) $id);
