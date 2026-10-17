<?php

namespace App\Queue\Quality;

use DomainException;

/** A quality check that may not be saved; `key` is its `errors.queue.*` message. */
class QaRefused extends DomainException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }
}
