<?php

namespace App\Inbox;

use App\Events\UserNotified;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\UserNotification;
use App\Push\Jobs\SendPushNotification;
use App\Push\PushManager;
use App\Push\PushMessageFactory;
use App\Support\SafeBroadcast;

/**
 * Persists a notification and broadcasts it in real time. Persistence
 * always happens (the bell/settings page and the tab badge read it back);
 * the broadcast is best-effort (SafeBroadcast), consumed by useNotifications'
 * `user.{id}` channel listener. Types listed in `crm.push.types` are also
 * pushed to the user's phones (mobile app spec §6): queued after commit,
 * best-effort as well — a push problem never breaks notify().
 */
final class UserNotifier
{
    public function notify(User $user, string $type, array $data): UserNotification
    {
        $notification = UserNotification::create(['user_id' => $user->id, 'type' => $type, 'data' => $data]);
        SafeBroadcast::send(new UserNotified($user->id, $type, $data + ['id' => $notification->id]));
        rescue(fn () => $this->queuePush($user, $notification), null, report: true);

        return $notification;
    }

    /** A push job only for a pushable type, with push switched on, to a user with an active device. */
    private function queuePush(User $user, UserNotification $notification): void
    {
        if (! PushMessageFactory::pushable((string) $notification->type) || ! app(PushManager::class)->enabled()) {
            return;
        }

        if (! DeviceToken::query()->active()->where('user_id', $user->id)->exists()) {
            return;
        }

        SendPushNotification::dispatch((int) $notification->id);
    }

    /** The same notification for every active supervisor and admin (queue alerts). */
    public function notifySupervisors(string $type, array $data): void
    {
        User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->isSupervisorOrAbove())
            ->each(fn (User $u) => $this->notify($u, $type, $data));
    }

    /** The same notification for every active holder of Ads authority (users.ads_authority, D4; not a role, R-30). */
    public function notifyAdsAuthority(string $type, array $data): void
    {
        User::query()->where('is_active', true)->where('ads_authority', true)->get()
            ->filter(fn (User $u) => $u->hasAdsAuthority())
            ->each(fn (User $u) => $this->notify($u, $type, $data));
    }

    /** The same notification for every active admin (an escalation left unanswered at the leader's desk). */
    public function notifyAdmins(string $type, array $data): void
    {
        User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->isAdmin())
            ->each(fn (User $u) => $this->notify($u, $type, $data));
    }
}
