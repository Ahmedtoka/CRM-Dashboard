<?php

namespace App\Confirmations\Events;

use App\Models\OrderConfirmation;
use Illuminate\Foundation\Events\Dispatchable;

/** Staff recorded a call outcome: confirmed | cancelled | unreachable. */
final class OrderConfirmationCalled
{
    use Dispatchable;

    public function __construct(public readonly OrderConfirmation $confirmation, public readonly string $outcome) {}
}
