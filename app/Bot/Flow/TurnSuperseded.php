<?php

namespace App\Bot\Flow;

use RuntimeException;

/** A model turn that will not be sent: a newer message of hers is answered together with it (TurnSupersede). */
final class TurnSuperseded extends RuntimeException
{
    public function __construct(public readonly float $costUsd = 0.0, public readonly ?string $tier = null, public readonly ?string $model = null)
    {
        parent::__construct('bot turn superseded by a newer customer message');
    }
}
