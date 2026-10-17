<?php

namespace App\Push;

use App\Models\DeviceToken;
use App\Models\User;
use App\Push\Drivers\FakePushSender;
use App\Push\Drivers\FcmPushSender;
use App\Push\Drivers\LogPushSender;
use App\Push\Drivers\NullPushSender;
use Illuminate\Support\Facades\Log;

/**
 * Picks the configured driver (`crm.push.driver`: null|log|fake|fcm) and keeps each
 * device row honest (review 01, I3): a success stamps last_push_at and clears the
 * failure count; only a device fault counts up, and a dead token — or MAX_FAILURES
 * device faults in a row — disables the device. Transient and configuration failures
 * are logged (never the token or the text) and leave the device alone.
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

    /**
     * @param  list<int>|null  $onlyDeviceIds  a retry goes only to the devices that failed transiently
     * @return array{devices: int, sent: int, failed: int, retry: list<int>}
     */
    public function sendToUser(User $user, PushMessage $message, ?array $onlyDeviceIds = null): array
    {
        $devices = DeviceToken::query()->active()->where('user_id', $user->id)
            ->when($onlyDeviceIds !== null, fn ($q) => $q->whereKey($onlyDeviceIds))->get();
        $sent = 0;
        $failed = 0;
        $retry = [];

        if (! $this->enabled()) {
            return ['devices' => $devices->count(), 'sent' => 0, 'failed' => 0, 'retry' => []];
        }

        foreach ($devices as $device) {
            $result = $this->sendToDevice($device, $message);

            if ($result->ok) {
                $sent++;

                continue;
            }

            $failed++;

            if ($result->transient) {
                $retry[] = (int) $device->id;
            }

            if (str_starts_with((string) $result->error, 'fcm_config')) {
                $configError ??= (string) $result->error;
            }
        }

        // A configuration problem (no credentials, wrong Firebase project) is loud, once per send.
        if (isset($configError)) {
            Log::error('push.config_error', ['driver' => $this->driverName(), 'user_id' => $user->id, 'error' => $configError]);
        }

        return ['devices' => $devices->count(), 'sent' => $sent, 'failed' => $failed, 'retry' => $retry];
    }

    public function sendToDevice(DeviceToken $device, PushMessage $message): PushResult
    {
        $result = rescue(fn () => $this->sender()->send($device, $message), fn () => PushResult::transient('driver_exception'), report: true);

        if ($result->ok) {
            $device->forceFill(['last_push_at' => now(), 'failure_count' => 0])->save();

            return $result;
        }

        Log::warning('push.failed', [
            'driver' => $this->driverName(),
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'type' => $message->type(),
            'error' => $result->error,
            'device_fault' => $result->deviceFault,
            'transient' => $result->transient,
        ]);

        if (! $result->deviceFault) {
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
