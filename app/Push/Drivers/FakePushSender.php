<?php

namespace App\Push\Drivers;

use App\Models\DeviceToken;
use App\Models\User;
use App\Push\PushMessage;
use App\Push\PushResult;
use App\Push\PushSender;
use PHPUnit\Framework\Assert;

/** Test driver: records every send; `failNext()` makes the following sends fail. */
final class FakePushSender implements PushSender
{
    /** @var list<array{device: DeviceToken, message: PushMessage}> */
    public array $sent = [];

    private ?PushResult $failure = null;

    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        if ($this->failure !== null) {
            return $this->failure;
        }

        $this->sent[] = ['device' => $device, 'message' => $message];

        return PushResult::sent();
    }

    public function failNext(string $error = 'fake_failure', bool $invalidToken = false): void
    {
        $this->failure = PushResult::failed($error, $invalidToken);
    }

    /** @return list<PushMessage> */
    public function messagesFor(User $user, ?string $type = null): array
    {
        return array_values(array_map(fn (array $s) => $s['message'], array_filter(
            $this->sent,
            fn (array $s) => (int) $s['device']->user_id === (int) $user->id && ($type === null || $s['message']->type() === $type),
        )));
    }

    public function assertSentTo(User $user, string $type): void
    {
        Assert::assertNotEmpty($this->messagesFor($user, $type), "No [{$type}] push was sent to user {$user->id}.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Unexpected pushes were sent.');
    }
}
