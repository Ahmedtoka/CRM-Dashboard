<?php

namespace App\Queue;

use RuntimeException;

/**
 * A check-in the queue refuses: `key` under `errors.queue.*`, the HTTP status to answer with,
 * and the replacements of the message (`time` for `shift_not_open`).
 */
class AttendanceRefused extends RuntimeException
{
    /** @param array<string, string> $replace */
    public function __construct(public readonly string $key, public readonly int $status, public readonly array $replace = [])
    {
        parent::__construct($key);
    }
}
