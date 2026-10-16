<?php

namespace App\Push\Drivers;

use App\Models\DeviceToken;
use App\Push\PushMessage;
use App\Push\PushResult;
use App\Push\PushSender;

/** Push switched off (CRM_PUSH_DRIVER unset): nothing leaves the server. PushManager never calls it for real sends. */
final class NullPushSender implements PushSender
{
    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        return PushResult::failed('push_disabled');
    }
}
