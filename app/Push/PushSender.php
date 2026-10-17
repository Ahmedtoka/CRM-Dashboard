<?php

namespace App\Push;

use App\Models\DeviceToken;

/**
 * One push to one device (spec D8). Drivers: null (push off, the default), log, fake
 * (tests) and fcm (Firebase Cloud Messaging HTTP v1). Bookkeeping on the device row
 * (failures, disabling) is PushManager's job, not the driver's.
 */
interface PushSender
{
    public function send(DeviceToken $device, PushMessage $message): PushResult;
}
