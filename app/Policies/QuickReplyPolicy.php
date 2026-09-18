<?php

namespace App\Policies;

use App\Enums\QuickReplyScope;
use App\Models\Conversation;
use App\Models\QuickReply;
use App\Models\User;

class QuickReplyPolicy
{
    public function use(User $user, QuickReply $reply): bool
    {
        return $reply->scope === QuickReplyScope::Shared || (int) $reply->user_id === (int) $user->id;
    }

    /**
     * Whether a reply may be used (rendered or sent) in a specific
     * conversation (spec §2.3): scope/ownership as `use()`, plus the reply's
     * own platform restriction — an empty `platforms` list means every
     * platform, otherwise the conversation's platform must be in the list.
     */
    public function useIn(User $user, QuickReply $reply, Conversation $conversation): bool
    {
        if (! $this->use($user, $reply)) {
            return false;
        }

        $platforms = $reply->platforms ?? [];

        return $platforms === [] || in_array($conversation->platform?->value, $platforms, true);
    }

    public function create(User $user, string $scope): bool
    {
        return $scope === QuickReplyScope::Personal->value || $user->isSupervisorOrAbove();
    }

    public function update(User $user, QuickReply $reply): bool
    {
        return $reply->scope === QuickReplyScope::Shared ? $user->isSupervisorOrAbove() : (int) $reply->user_id === (int) $user->id;
    }

    public function delete(User $user, QuickReply $reply): bool
    {
        return $this->update($user, $reply);
    }
}
