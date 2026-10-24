<?php

namespace App\Cases\Returns;

use InvalidArgumentException;

/** A step the lifecycle refuses: out of order, wrong kind, or a missing fact. `code` is the `returns.errors.*` key. */
final class ReturnTransitionException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
