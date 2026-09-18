<?php

namespace App\Policies;

use App\Enums\AttachmentStatus;
use App\Models\MessageAttachment;
use App\Models\User;

class MessageAttachmentPolicy
{
    public function view(User $user, MessageAttachment $a): bool
    {
        if ($a->message_id === null) {
            return (int) $a->uploaded_by === (int) $user->id;
        }

        $conversation = $a->message?->conversation;

        return $conversation !== null && $user->canAccessPlatform($conversation->platform);
    }

    public function retry(User $user, MessageAttachment $a): bool
    {
        return $a->message_id !== null && $this->view($user, $a) && $a->status === AttachmentStatus::Failed;
    }
}
