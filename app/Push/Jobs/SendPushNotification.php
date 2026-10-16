<?php

namespace App\Push\Jobs;

use App\Models\UserNotification;
use App\Push\PushManager;
use App\Push\PushMessageFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pushes one staff notification (a `user_notifications` row) to its user's active
 * devices. Runs on the existing `outbound` queue (no new worker) and only after the
 * notification's transaction commits. A notification that is gone, or a user who was
 * deactivated meanwhile, pushes nothing.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('outbound');
        $this->afterCommit();
    }

    public function handle(PushManager $push, PushMessageFactory $factory): void
    {
        $notification = UserNotification::query()->with('user')->find($this->notificationId);
        $user = $notification?->user;

        if ($user === null || ! $user->is_active) {
            return;
        }

        $message = $factory->make($notification, $user);

        if ($message !== null) {
            $push->sendToUser($user, $message);
        }
    }
}
