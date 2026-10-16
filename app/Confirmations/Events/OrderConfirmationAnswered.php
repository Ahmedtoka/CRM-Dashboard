<?php

namespace App\Confirmations\Events;

use App\Models\OrderConfirmation;
use Illuminate\Foundation\Events\Dispatchable;

/** A customer answered a confirmation: answer is confirm | edit | cancel. */
final class OrderConfirmationAnswered
{
    use Dispatchable;

    public function __construct(public readonly OrderConfirmation $confirmation, public readonly string $answer) {}
}
