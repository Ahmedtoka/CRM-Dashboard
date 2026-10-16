<?php

namespace App\Push;

use App\Models\DeviceToken;
use App\Models\User;
use App\Push\Drivers\FakePushSender;
use App\Push\Drivers\FcmPushSender;
use App\Push\Drivers\LogPushSender;
use App\Push\Drivers\NullPushSender;

/**
 * Picks the configured driver (`crm.push.driver`: null|log|fake|fcm) and keeps each
 * device row honest: a success stamps last_push_at and clears the failure count; a
 * failure counts up and a dead token — or MAX_FAILURES failures in a row — disables
 * the device (spec §6).
 */
class PushManager
{
    public const MAX_FAILURES = 5;

    public function driverName(): ?string
    {
        $driver = config('crm.push.driver');

        return in_array($driver, ['log', 'fake', 'fcm'], true) ? $driver : null;
    }

    public function enabled(): bool
    {
        return $this->driverName() !== null;
    }

    public function sender(): PushSender
    {
        return match ($this->driverName()) {
            'log' => app(LogPushSender::class),
            'fake' => app(FakePushSender::class),
            'fcm' => app(FcmPushSender::class),
            default => app(NullPushSender::class),
        };
    }

    /** @return array{devices: int, sent: int, failed: int} */
    public function sendToUser(User $user, PushMessage $message): array
    {
        $devices = DeviceToken::query()->active()->where('user_id', $user->id)->get();
        $sent = 0;
        $failed = 0;

        if (! $this->enabled()) {
            return ['devices' => $devices->count(), 'sent' => 0, 'failed' => 0];
        }

        foreach ($devices as $device) {
            $this->sendToDevice($device, $message)->ok ? $sent++ : $failed++;
        }

        return ['devices' => $devices->count(), 'sent' => $sent, 'failed' => $failed];
    }

    public function sendToDevice(DeviceToken $device, PushMessage $message): PushResult
    {
        $result = rescue(fn () => $this->sender()->send($device, $message), fn () => PushResult::failed('driver_exception'), report: true);

        if ($result->ok) {
            $device->forceFill(['last_push_at' => now(), 'failure_count' => 0])->save();

            return $result;
        }

        $failures = (int) $device->failure_count + 1;
        $device->forceFill([
            'failure_count' => min($failures, 65535),
            'disabled_at' => $result->invalidToken || $failures >= self::MAX_FAILURES ? now() : $device->disabled_at,
        ])->save();

        return $result;
    }
}
