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
 *
 * Transient failures (FCM auth/5xx/429/connection) are retried with backoff by a new job
 * that targets only the devices that failed, so a device that already got the push never
 * gets it twice (review 01, I3/M9). The job itself is never retried by the worker.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Delay before retry n (seconds); its length is the number of retries. */
    public const BACKOFF = [30, 120, 600];

    public int $tries = 1;

    /**
     * @param  list<int>|null  $deviceIds  null = every active device of the user
     */
    public function __construct(public readonly int $notificationId, public readonly ?array $deviceIds = null, public readonly int $attempt = 0)
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

        if ($message === null) {
            return;
        }

        $result = $push->sendToUser($user, $message, $this->deviceIds);

        if ($result['retry'] !== [] && $this->attempt < count(self::BACKOFF)) {
            self::dispatch($this->notificationId, $result['retry'], $this->attempt + 1)
                ->delay(now()->addSeconds(self::BACKOFF[$this->attempt]));
        }
    }
}
