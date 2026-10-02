<?php

use App\Enums\Platform;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// All-platform inbox feed: supervisors and admins only. Moderators get the per-platform feeds below,
// so a message body never reaches a moderator who may not see that platform (UI overhaul Task 4b).
Broadcast::channel('inbox', fn (User $user) => (bool) $user->is_active && $user->isSupervisorOrAbove());

// Per-platform inbox feed: active users who may see that platform (supervisor+ see all).
Broadcast::channel('inbox.platform.{platform}', function (User $user, string $platform) {
    $p = Platform::tryFrom($platform);

    return $p !== null && (bool) $user->is_active && $user->canAccessPlatform($p);
});

// Comments screen feed: any active inbox staff member (never the Ads Hub roles).
Broadcast::channel('comments', fn (User $user) => (bool) $user->is_active && $user->isInboxStaff());

// Presence per conversation: users allowed on the conversation's platform.
Broadcast::channel('conversation.{conversation}', function (User $user, Conversation $conversation) {
    if (! $user->is_active || ! $user->canAccessPlatform($conversation->platform)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'color' => $user->color];
});

// Handover queue live board: any active inbox staff member (Task 6 narrows what each one sees).
Broadcast::channel('board', fn (User $user) => (bool) $user->is_active && $user->isInboxStaff());

// Integration progress (Shopify import): active admins only.
Broadcast::channel('integrations', fn (User $user) => (bool) $user->is_active && $user->isAdmin());

// Personal notifications: own id only.
Broadcast::channel('user.{id}', fn (User $user, $id) => (int) $user->id === (int) $id);
