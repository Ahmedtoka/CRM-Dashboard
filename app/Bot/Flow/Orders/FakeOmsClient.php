<?php

namespace App\Bot\Flow\Orders;

use Throwable;

/** Offline OMS: statuses set by tests/demo; unknown orders return null. Reset in tests/TestCase.php. */
class FakeOmsClient implements OmsClient
{
    /** @var array<string, OmsStatus> keyed by order number without "#" */
    public static array $statuses = [];

    public static ?Throwable $throw = null;

    public static function reset(): void
    {
        self::$statuses = [];
        self::$throw = null;
    }

    public function status(string $orderNumber): ?OmsStatus
    {
        if (self::$throw !== null) {
            throw self::$throw;
        }

        return self::$statuses[ltrim(trim($orderNumber), '#')] ?? null;
    }
}
