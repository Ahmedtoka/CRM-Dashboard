<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function view(User $user, Comment $comment): bool
    {
        $platform = $comment->post?->platform;

        return $platform !== null && $user->canAccessPlatform($platform);
    }

    public function reply(User $user, Comment $comment): bool
    {
        return $this->view($user, $comment);
    }
}
