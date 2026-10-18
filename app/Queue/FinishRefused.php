<?php

namespace App\Queue;

use RuntimeException;

/**
 * «خلصت» refused (close-cycle-FINAL §4.1, §5.1): `key` is the API's error key
 * (`outcome_required`, `customer_waiting`, `already_closed`, `not_your_window`, `case_exists`,
 * `undo_expired`, `not_amendable`), `status` its HTTP status, `extra` what the body carries.
 */
class FinishRefused extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(public readonly string $key, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($key);
    }
}
