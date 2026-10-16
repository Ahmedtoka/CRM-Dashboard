<?php

namespace App\Push\Commands;

use App\Models\User;
use App\Push\PushManager;
use App\Push\PushMessageFactory;
use Illuminate\Console\Command;

/** `php artisan crm:push-test {user}` — the «تجربة إشعار» push of POST devices/test, from the CLI. */
class PushTestCommand extends Command
{
    protected $signature = 'crm:push-test {user : User id or email}';

    protected $description = 'Send a test push to every active device of a user';

    public function handle(PushManager $push, PushMessageFactory $factory): int
    {
        $ref = (string) $this->argument('user');
        $user = ctype_digit($ref) ? User::query()->find((int) $ref) : User::query()->where('email', $ref)->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $result = $push->sendToUser($user, $factory->test($user));

        $this->table(['driver', 'devices', 'sent', 'failed'], [[$push->driverName() ?? 'null', $result['devices'], $result['sent'], $result['failed']]]);

        if ($push->driverName() === null) {
            $this->warn('CRM_PUSH_DRIVER is not set: nothing was sent.');
        }

        return self::SUCCESS;
    }
}
