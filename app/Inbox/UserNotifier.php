<?php

namespace App\Inbox;

use App\Events\UserNotified;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\SafeBroadcast;

/**
 * Persists a notification and broadcasts it in real time. Persistence
 * always happens (the bell/settings page and the tab badge read it back);
 * the broadcast is best-effort (SafeBroadcast), consumed by useNotifications'
 * `user.{id}` channel listener.
 */
final class UserNotifier
{
    public function notify(User $user, string $type, array $data): UserNotification
    {
        $notification = UserNotification::create(['user_id' => $user->id, 'type' => $type, 'data' => $data]);
        SafeBroadcast::send(new UserNotified($user->id, $type, $data + ['id' => $notification->id]));

        return $notification;
    }
}
