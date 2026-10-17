<?php

namespace App\Confirmations\Events;

use App\Models\OrderConfirmation;
use Illuminate\Foundation\Events\Dispatchable;

/** The no-response deadline passed: the order goes on the staff call list. */
final class OrderConfirmationNoResponse
{
    use Dispatchable;

    public function __construct(public readonly OrderConfirmation $confirmation) {}
}
