<?php

namespace App\Push\Drivers;

use App\Models\DeviceToken;
use App\Push\PushMessage;
use App\Push\PushResult;
use App\Push\PushSender;
use Illuminate\Support\Facades\Log;

/**
 * Local/demo driver: records that a push would have gone out. Never logs the device
 * token or the body (the body can carry a customer's first name or an excerpt).
 */
final class LogPushSender implements PushSender
{
    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        Log::info('push.sent', [
            'driver' => 'log',
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'type' => $message->type(),
            'channel' => $message->channel,
            'priority' => $message->priority,
        ]);

        return PushResult::sent();
    }
}
