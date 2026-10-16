<?php

namespace App\Confirmations\Events;

use App\Models\OrderConfirmation;
use Illuminate\Foundation\Events\Dispatchable;

/** The customer sent the edit details after tapping «تعديل»: they are in confirmation->edit_note. */
final class OrderConfirmationEditNote
{
    use Dispatchable;

    public function __construct(public readonly OrderConfirmation $confirmation) {}
}
